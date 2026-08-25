<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Run Local Falcon geo-grid scans via the On-Demand API and persist results.
 *
 * POST /v1/scan/ is synchronous: one call = one full grid scan = the
 * response carries every point's rank plus ARP/ATRP/SoLV aggregates
 * (contract: docs.localfalcon.com/openapi.yaml, fetched 2026-08-24).
 * Credits: grid_size² per keyword per run — 5×5 × 3 keywords = 75/run.
 *
 * Companion to localfalcon:sync, which mirrors scans run in their UI; this
 * command originates scans, so the weekly cadence never depends on anyone
 * remembering to click.
 */
class LocalFalconScan extends Command
{
    protected $signature = 'localfalcon:scan
        {--keyword=* : Override the configured keyword list}
        {--dry-run : Show what would run without spending credits}';

    protected $description = 'Run the configured Local Falcon geo-grid scans (spends credits) and store results.';

    public function handle(): int
    {
        $key = (string) config('services.localfalcon.key');
        if ($key === '') {
            $this->warn('LOCALFALCON_API_KEY not set — skipping.');

            return self::SUCCESS;
        }

        $placeId = (string) config('services.google.business_profile.place_id');
        $area = \App\Models\AreaServed::where('slug', 'arlington-heights')->first();
        if ($placeId === '' || ! $area?->latitude) {
            $this->error('Missing place_id or Arlington Heights coordinates.');

            return self::FAILURE;
        }

        $keywords = (array) ($this->option('keyword') ?: config('services.localfalcon.keywords', []));
        $gridSize = (string) config('services.localfalcon.grid_size', '5');
        $radius = (string) config('services.localfalcon.radius', '5');

        $failures = 0;
        foreach ($keywords as $keyword) {
            if ($this->option('dry-run')) {
                $this->line("  [dry-run] would scan '{$keyword}' {$gridSize}×{$gridSize} @ {$radius}mi (" . ((int) $gridSize ** 2) . ' credits)');

                continue;
            }

            $resp = Http::asForm()->timeout(180)->post('https://api.localfalcon.com/v1/scan/', [
                'api_key' => $key,
                'place_id' => $placeId,
                'keyword' => $keyword,
                'lat' => (string) $area->latitude,
                'lng' => (string) $area->longitude,
                'grid_size' => $gridSize,
                'radius' => $radius,
                'measurement' => 'mi',
            ]);

            $data = $resp->json('data');
            if (! $resp->successful() || ! is_array($data) || ! $resp->json('success')) {
                $failures++;
                $this->error("  '{$keyword}': HTTP {$resp->status()} " . mb_substr((string) $resp->body(), 0, 180));

                continue;
            }

            // Slim per-point results: rank per coordinate + the pack leaders,
            // not every competitor at every point (that can be 500+ entries).
            $points = collect($data['results'] ?? [])->map(fn ($pt) => [
                'lat' => $pt['lat'] ?? null,
                'lng' => $pt['lng'] ?? null,
                'rank' => $pt['rank'] ?? false,
            ])->all();

            $leaders = collect($data['results'] ?? [])
                ->flatMap(fn ($pt) => array_slice((array) ($pt['results'] ?? []), 0, 3))
                ->groupBy('place_id')
                ->map(fn ($g) => ['business' => $g->first()['business'] ?? '?', 'appearances' => $g->count()])
                ->sortByDesc('appearances')->take(8)->values()->all();

            \App\Support\Tenancy::table('local_falcon_scans')->insert([
                'site_id' => \App\Models\Site::current()?->id,
                'falcon_scan_id' => 'ondemand-' . now()->format('Ymd-His') . '-' . substr(md5($keyword), 0, 8),
                'keyword' => $keyword,
                'place_id' => $placeId,
                'grid_size' => (int) $gridSize,
                'arp' => is_numeric($data['arp'] ?? null) ? round((float) $data['arp'], 2) : null,
                'atrp' => is_numeric($data['atrp'] ?? null) ? round((float) $data['atrp'], 2) : null,
                'solv' => is_numeric($data['solv'] ?? null) ? round((float) $data['solv'], 2) : null,
                'scanned_at' => now(),
                'raw' => json_encode([
                    'points_total' => $data['points'] ?? null,
                    'found' => $data['found'] ?? null,
                    'percent' => $data['percent'] ?? null,
                    'grid' => $points,
                    'pack_leaders' => $leaders,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->info(sprintf(
                "  '%s': in pack at %s/%s points (%.0f%%) · avg rank when present %.1f · SoLV %.1f%%",
                $keyword,
                $data['found'] ?? '?',
                $data['points'] ?? '?',
                (float) ($data['percent'] ?? 0),
                (float) ($data['arp'] ?? 0),
                (float) ($data['solv'] ?? 0)
            ));
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
