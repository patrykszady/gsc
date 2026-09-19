<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\Site;
use App\Models\Town;
use App\Models\TownImport;
use App\Services\OpenStreetMapGeocoder;
use App\Support\Areas\MajorCityMarkets;
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
        {--neighbourhood-radius= : half-height in degrees of the tighter box used for the neighbourhood/suburb/quarter pass (default: config areas.neighbourhood_radius_degrees — the same value App\Support\Areas\MajorCityMarkets checks addability against, so leave this alone unless you mean to move both)}
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

        foreach ($boxes as $label => $box) {
            [$count, $ok] = $this->importBox($geocoder, $label, $box);
            $imported += $count;
            $failed += $ok ? 0 : 1;
        }

        // A second, tighter pass for named subdivisions — OSM's
        // place=neighbourhood|suburb|quarter. Chicago alone has 307 of those
        // around its centre (218 neighbourhood, 77 suburb — OSM's tag for
        // Chicago's 77 official community areas — 12 quarter), which the
        // settlement pass above never asks for. Skipped for a hand-drawn
        // --bbox: whoever typed the box can widen it themselves, and a raw
        // bbox has no "market" to keep the tighter query from bleeding into
        // a neighbouring one.
        if (! $this->option('bbox')) {
            foreach ($this->neighbourhoodBoxes() as $label => $box) {
                [$count, $ok] = $this->importBox($geocoder, "{$label} neighbourhoods", $box, AreaServed::NEIGHBOURHOOD_KINDS);
                $imported += $count;
                $failed += $ok ? 0 : 1;
            }
        }

        $this->newLine();
        $this->info("Done. {$imported} towns upserted, {$failed} box(es) failed.");
        $this->line('Gazetteer now holds '.Town::count().' towns.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Fetch one box with retries, store what comes back, and record it as
     * imported. Shared by the settlement pass and the neighbourhood pass —
     * only the kinds asked for and the label differ.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box
     * @param  array<int, string>|null  $kinds
     * @return array{0: int, 1: bool} [towns stored, box succeeded]
     */
    private function importBox(OpenStreetMapGeocoder $geocoder, string $label, array $box, ?array $kinds = null): array
    {
        [$south, $west, $north, $east] = $box;

        $this->line("Importing <options=bold>{$label}</> ({$south},{$west} → {$north},{$east})…");

        $towns = null;
        $attempts = (int) $this->option('retries');

        for ($i = 1; $i <= $attempts; $i++) {
            // Patient: an import can afford to wait where a request cannot.
            $towns = $geocoder->townsInBounds($south, $west, $north, $east, 90.0, $kinds);

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

            return [0, false];
        }

        $count = $this->store($towns);

        TownImport::updateOrCreate(
            ['south' => $south, 'west' => $west, 'north' => $north, 'east' => $east],
            ['towns_found' => count($towns)],
        );

        $this->info("  {$count} towns stored (".count($towns).' returned)');

        return [$count, true];
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
        $areas = AreaServed::withoutSiteScope()
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
        $this->line('Gazetteer now holds '.Town::count().' towns.');
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
        if ($raw = $this->option('bbox')) {
            $parts = array_map('floatval', explode(',', (string) $raw));

            if (count($parts) !== 4) {
                $this->error('--bbox needs four comma-separated numbers: south,west,north,east');

                return [];
            }

            return ['bbox' => $parts];
        }

        return $this->marketBoxes((float) $this->option('radius'));
    }

    /**
     * The same markets, boxed tighter — for the neighbourhood/suburb/quarter
     * pass. The settlement radius (0.45°, ~50km) is wide enough to straddle
     * two markets' territory; a neighbourhood box that wide would pull in
     * named subdivisions that belong to a market next door. --bbox has no
     * notion of "market", so it never reaches this — see handle().
     *
     * No literal default here: MajorCityMarkets::radiusDegrees() (config
     * areas.neighbourhood_radius_degrees) is the one place that number
     * lives, because AreaMapController::createFromMap and TownCatalog read
     * the exact same value to decide what THIS PASS is allowed to have
     * imported. A --neighbourhood-radius override only changes what gets
     * fetched here, not what the addability check accepts.
     *
     * @return array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    private function neighbourhoodBoxes(): array
    {
        $radius = $this->option('neighbourhood-radius');

        return $this->marketBoxes($radius !== null ? (float) $radius : MajorCityMarkets::radiusDegrees());
    }

    /**
     * Every declared market (of every site, via Tenancy::for — see the class
     * docblock) boxed at $radius degrees, keyed "{site}/{market}".
     *
     * @return array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    private function marketBoxes(float $radius): array
    {
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

                // Same box shape MajorCityMarkets checks a point against —
                // sharing it means a town this pass actually imports is
                // always, by construction, "inside" for the addability check.
                $boxes["{$site->slug}/{$slug}"] = array_map(
                    fn (float $n) => round($n, 7),
                    MajorCityMarkets::boxAround((float) $market['lat'], (float) $market['lng'], $radius),
                );
            }
        }

        return $boxes;
    }
}
