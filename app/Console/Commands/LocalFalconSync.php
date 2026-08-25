<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Pull recent Local Falcon geo-grid scans into local_falcon_scans.
 *
 * Local Falcon answers the question nothing else in this stack can: where do
 * we rank IN THE MAP PACK, block by block, across the service area — the
 * channel that actually takes the clicks on our commercial SERPs. This
 * command only mirrors results; scans themselves are scheduled inside Local
 * Falcon (auto-scans on the paid plans).
 *
 * Defensive by design: the shapes here follow Local Falcon's public v1 API
 * docs; every field is optional-read, the raw payload is kept verbatim, and
 * an unexpected response shape warns instead of throwing — first real run
 * happens when LOCALFALCON_API_KEY lands in .env.
 */
class LocalFalconSync extends Command
{
    protected $signature = 'localfalcon:sync {--limit=25 : Most recent scans to mirror}';

    protected $description = 'Mirror recent Local Falcon geo-grid scan results (map-pack visibility) into the local DB.';

    public function handle(): int
    {
        $key = (string) config('services.localfalcon.key');
        if ($key === '') {
            $this->comment('LOCALFALCON_API_KEY not set — skipping.');

            return self::SUCCESS;
        }

        $resp = Http::timeout(30)->get('https://api.localfalcon.com/v1/reports/', [
            'api_key' => $key,
            'limit' => (int) $this->option('limit'),
        ]);

        if (! $resp->successful()) {
            $this->error('Local Falcon API: HTTP ' . $resp->status() . ' ' . mb_substr($resp->body(), 0, 200));

            return self::FAILURE;
        }

        $reports = $resp->json('data.reports') ?? $resp->json('reports') ?? $resp->json('data') ?? [];
        if (! is_array($reports) || $reports === []) {
            $this->warn('No reports in response — check the account has completed scans. Body head: ' . mb_substr($resp->body(), 0, 200));

            return self::SUCCESS;
        }

        $written = 0;
        foreach ($reports as $r) {
            if (! is_array($r)) {
                continue;
            }
            $scanId = (string) ($r['report_key'] ?? $r['id'] ?? $r['scan_id'] ?? '');
            if ($scanId === '') {
                continue;
            }

            $isNew = ! \App\Support\Tenancy::table('local_falcon_scans')->where('scan_id', $scanId)->exists();

            // Full report detail for NEW scans: the list rows carry only the
            // aggregates, but the paid tier includes the complete per-point
            // grid — rank at every coordinate plus who occupies the pack.
            // Slimmed before storing (rank-per-point + top pack occupants),
            // since the raw detail repeats every competitor at every point.
            $detail = null;
            if ($isNew) {
                $d = Http::asForm()->timeout(60)->post("https://api.localfalcon.com/v1/reports/{$scanId}/", [
                    'api_key' => $key,
                ]);
                if ($d->successful() && is_array($d->json('data'))) {
                    // Real shape (verified against a live report 2026-08-24):
                    // data_points[] = {lat,lng,found,rank,count,results[]},
                    // found_in = points where the business appeared, plus
                    // ready-made heatmap/image/public_url renders.
                    $data = $d->json('data');
                    $points = collect($data['data_points'] ?? [])->map(fn ($pt) => [
                        'lat' => $pt['lat'] ?? null,
                        'lng' => $pt['lng'] ?? null,
                        'rank' => ($pt['found'] ?? false) ? ($pt['rank'] ?? false) : false,
                    ])->all();
                    $leaders = collect($data['data_points'] ?? [])
                        ->flatMap(fn ($pt) => array_slice((array) ($pt['results'] ?? []), 0, 3))
                        ->groupBy('place_id')
                        ->map(fn ($g) => ['business' => $g->first()['name'] ?? $g->first()['business'] ?? '?', 'appearances' => $g->count()])
                        ->sortByDesc('appearances')->take(8)->values()->all();
                    $detail = [
                        'grid' => $points,
                        'pack_leaders' => $leaders,
                        'found' => $data['found_in'] ?? null,
                        'points_total' => $data['points'] ?? null,
                        'center' => ['lat' => $data['lat'] ?? null, 'lng' => $data['lng'] ?? null],
                        'radius' => ($data['radius'] ?? null) . ($data['measurement'] ?? ''),
                        'heatmap' => $data['heatmap'] ?? $data['image'] ?? null,
                        'public_url' => $data['public_url'] ?? null,
                    ];
                }
            }

            \App\Support\Tenancy::table('local_falcon_scans')->updateOrInsert(
                ['scan_id' => $scanId],
                [
                    'site_id' => \App\Models\Site::current()?->id,
                    'keyword' => mb_substr((string) ($r['keyword'] ?? $r['search_term'] ?? ''), 0, 191),
                    'scanned_at' => isset($r['date']) ? Carbon::parse($r['date']) : (isset($r['timestamp']) ? Carbon::createFromTimestamp((int) $r['timestamp']) : null),
                    'arp' => is_numeric($r['arp'] ?? null) ? $r['arp'] : null,
                    'atrp' => is_numeric($r['atrp'] ?? null) ? $r['atrp'] : null,
                    'solv' => is_numeric(str_replace('%', '', (string) ($r['solv'] ?? ''))) ? (float) str_replace('%', '', (string) $r['solv']) : null,
                    'grid_points' => is_numeric($r['grid_size'] ?? null) ? (int) $r['grid_size'] : null,
                    'in_top3' => is_numeric($r['in_top3'] ?? null) ? (int) $r['in_top3'] : null,
                    'raw' => json_encode($detail ? array_merge($r, ['detail' => $detail]) : $r),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $written++;
        }

        $this->info("Mirrored {$written} scan(s).");

        $this->mirrorReportFamilies($key);

        return self::SUCCESS;
    }

    /**
     * Mirror every other report family the paid tier exposes. Generic on
     * purpose: one loop, one table, full payload archived — each family's
     * dashboard treatment can pick fields later without another API round.
     */
    protected function mirrorReportFamilies(string $key): void
    {
        $families = [
            'trend' => '/v1/trend-reports/',
            'competitor' => '/v1/competitor-reports/',
            'keyword' => '/v1/keyword-reports/',
            'location' => '/v1/location-reports/',
            'campaign' => '/v1/campaigns/',
            'guard' => '/v1/guard/',
            'reviews' => '/v1/reviews/',
        ];

        foreach ($families as $family => $path) {
            $resp = Http::timeout(30)->get('https://api.localfalcon.com' . $path, [
                'api_key' => $key,
                'limit' => 25,
            ]);
            if (! $resp->successful()) {
                $this->warn("  {$family}: HTTP {$resp->status()}");

                continue;
            }

            $data = (array) ($resp->json('data') ?? []);
            $rows = null;
            foreach ($data as $v) {
                if (is_array($v) && array_is_list($v)) {
                    $rows = $v;
                    break;
                }
            }

            $new = 0;
            foreach ((array) $rows as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $rk = (string) ($r['report_key'] ?? $r['place_id'] ?? $r['id'] ?? '');
                if ($rk === '') {
                    continue;
                }

                $reportedAt = null;
                if (! empty($r['timestamp']) && is_numeric($r['timestamp'])) {
                    $reportedAt = Carbon::createFromTimestamp((int) $r['timestamp']);
                } elseif (! empty($r['date'])) {
                    try {
                        $reportedAt = Carbon::parse($r['date']);
                    } catch (\Throwable) {
                    }
                }

                $exists = \App\Support\Tenancy::table('local_falcon_reports')
                    ->where('family', $family)->where('report_key', $rk)->exists();
                if ($exists) {
                    continue;
                }

                \App\Support\Tenancy::table('local_falcon_reports')->insert([
                    'site_id' => \App\Models\Site::current()?->id,
                    'family' => $family,
                    'report_key' => $rk,
                    'keyword' => mb_substr((string) ($r['keyword'] ?? $r['search_term'] ?? ''), 0, 191) ?: null,
                    'reported_at' => $reportedAt,
                    'payload' => json_encode($r),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $new++;
            }

            if ($new > 0 || is_array($rows)) {
                $this->line("  {$family}: " . ($new > 0 ? "+{$new} new" : 'up to date'));
            }
        }
    }
}
