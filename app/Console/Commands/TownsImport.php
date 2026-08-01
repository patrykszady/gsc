<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\Town;
use App\Models\TownImport;
use App\Services\OpenStreetMapGeocoder;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Import towns into the local gazetteer from OpenStreetMap.
 *
 * This is where the Overpass dependency now lives. Being slow here is fine —
 * nobody is watching a map — so it retries patiently instead of failing fast,
 * which is the opposite of what a request-time call must do.
 *
 *   php artisan towns:import --market=chicago     # one declared market
 *   php artisan towns:import --all-markets        # every market of every site
 *   php artisan towns:import --bbox=41.5,-88.2,42.2,-87.5
 */
class TownsImport extends Command
{
    protected $signature = 'towns:import
        {--bbox= : south,west,north,east}
        {--market= : slug of a market from a site\'s markets.php}
        {--all-markets : every market declared by every site}
        {--radius=0.45 : half-height in degrees of the box drawn around a market}
        {--retries=4 : attempts per box before giving up}
        {--from-areas : seed from existing AreaServed rows instead of Overpass}';

    protected $description = 'Cache towns from OpenStreetMap so the service-area map never queries Overpass live.';

    public function handle(OpenStreetMapGeocoder $geocoder): int
    {
        if ($this->option('from-areas')) {
            return $this->seedFromAreas();
        }

        $boxes = $this->boxes();

        if ($boxes === []) {
            $this->error('Nothing to import. Pass --bbox, --market or --all-markets.');

            return self::FAILURE;
        }

        $imported = 0;
        $failed = 0;

        foreach ($boxes as $label => [$south, $west, $north, $east]) {
            $this->line("Importing <options=bold>{$label}</> ({$south},{$west} → {$north},{$east})…");

            $towns = null;
            $attempts = (int) $this->option('retries');

            for ($i = 1; $i <= $attempts; $i++) {
                // Patient: an import can afford to wait where a request cannot.
                $towns = $geocoder->townsInBounds($south, $west, $north, $east, 90.0);

                if ($towns !== null) {
                    break;
                }

                // Overpass rejects instantly when throttled and hangs when
                // loaded; a widening pause covers both.
                $wait = 5 * $i;
                $this->warn("  attempt {$i}/{$attempts} failed — retrying in {$wait}s");
                sleep($wait);
            }

            if ($towns === null) {
                $this->error("  gave up on {$label}");
                $failed++;

                continue;
            }

            $count = $this->store($towns);
            $imported += $count;

            TownImport::updateOrCreate(
                ['south' => $south, 'west' => $west, 'north' => $north, 'east' => $east],
                ['towns_found' => count($towns)],
            );

            $this->info("  {$count} towns stored (" . count($towns) . ' returned)');
        }

        $this->newLine();
        $this->info("Done. {$imported} towns upserted, {$failed} box(es) failed.");
        $this->line('Gazetteer now holds ' . Town::count() . ' towns.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Seed the gazetteer from service areas every tenant already has.
     *
     * Overpass goes down — it was returning nothing at all while this was
     * being built — and the map should not be empty until it returns. These
     * are real, already-geocoded towns, and because the gazetteer is shared
     * they become candidate dots for the tenants that DON'T serve them yet:
     * J. Peterson Design immediately sees the Chicagoland towns
     * gs.construction covers.
     */
    private function seedFromAreas(): int
    {
        $areas = \App\Models\AreaServed::withoutSiteScope()
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->get(['city', 'latitude', 'longitude']);

        $towns = $areas->map(fn ($a) => [
            'name' => (string) $a->city,
            'lat' => (float) $a->latitude,
            'lng' => (float) $a->longitude,
            'kind' => 'town',
        ])->all();

        $count = $this->store($towns);

        $this->info("Seeded {$count} towns from existing service areas.");
        $this->line('Gazetteer now holds ' . Town::count() . ' towns.');
        $this->comment('Run without --from-areas to enrich from OpenStreetMap when Overpass is reachable.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{name: string, lat: float, lng: float}>  $towns
     */
    private function store(array $towns): int
    {
        $rows = collect($towns)
            ->map(fn (array $t) => [
                'name' => $t['name'],
                'state' => $t['state'] ?? null,
                'latitude' => round($t['lat'], 7),
                'longitude' => round($t['lng'], 7),
                'kind' => $t['kind'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if ($rows === []) {
            return 0;
        }

        // Upsert on the identity index so re-importing a region refreshes
        // rather than duplicating.
        foreach (array_chunk($rows, 200) as $chunk) {
            Town::upsert($chunk, ['name', 'state', 'latitude', 'longitude'], ['kind', 'updated_at']);
        }

        return count($rows);
    }

    /**
     * Boxes to import, keyed by a human label.
     *
     * @return array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    private function boxes(): array
    {
        $radius = (float) $this->option('radius');

        if ($raw = $this->option('bbox')) {
            $parts = array_map('floatval', explode(',', (string) $raw));

            if (count($parts) !== 4) {
                $this->error('--bbox needs four comma-separated numbers: south,west,north,east');

                return [];
            }

            return ['bbox' => $parts];
        }

        $boxes = [];
        $wanted = $this->option('market');

        foreach (Site::listAll() as $site) {
            // markets.php is a per-site config overlay, so it only resolves
            // inside that tenant's context.
            $markets = Tenancy::for($site, fn () => (array) config('markets.list', []));

            foreach ($markets as $market) {
                if (! isset($market['lat'], $market['lng'])) {
                    continue;
                }

                $slug = (string) ($market['slug'] ?? '');

                if ($wanted && $slug !== $wanted) {
                    continue;
                }

                if (! $wanted && ! $this->option('all-markets')) {
                    continue;
                }

                // Longitude degrees are narrower than latitude at these
                // latitudes; widening keeps the box roughly square on screen.
                $boxes["{$site->slug}/{$slug}"] = [
                    round($market['lat'] - $radius, 7),
                    round($market['lng'] - $radius * 1.35, 7),
                    round($market['lat'] + $radius, 7),
                    round($market['lng'] + $radius * 1.35, 7),
                ];
            }
        }

        return $boxes;
    }
}
