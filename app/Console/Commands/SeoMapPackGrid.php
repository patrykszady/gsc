<?php

namespace App\Console\Commands;

use App\Services\DataForSeoService;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly geo-grid map-pack scan: the Google Maps results at every point of
 * an N×N grid over the service area, per keyword, via DataForSEO
 * (~$0.002 a point — 121 points × 3 keywords ≈ $0.73 a run). Records, per
 * keyword: rank at every point, average rank where found (ARP), average
 * rank with not-found as 21 (ATRP), share of points in the 3-pack (SoLV),
 * and every business seen with its pack appearances, reviews and rating —
 * the same shape the map-pack card and the competitor reader consume.
 */
class SeoMapPackGrid extends Command
{
    protected $signature = 'seo:map-pack-grid {--keywords= : CSV override of config seo.map_pack.keywords} {--budget=2} {--dry-run : Print the grid and the estimate, spend nothing}';

    protected $description = 'Geo-grid map-pack scan of the service area via DataForSEO Google Maps into map_pack_scans';

    public function handle(DataForSeoService $dfs): int
    {
        if (! $dfs->isConfigured() || ! Schema::hasTable('map_pack_scans')) {
            $this->comment('DataForSEO not configured or table missing — skipping.');

            return self::SUCCESS;
        }
        $cfg = (array) config('seo.map_pack', []);
        $lat = (float) ($cfg['center_lat'] ?? 0);
        $lng = (float) ($cfg['center_lng'] ?? 0);
        $n = max(3, (int) ($cfg['grid_size'] ?? 11));
        $radius = (float) ($cfg['radius_miles'] ?? 15);
        $keywords = array_values(array_filter(array_map('trim', explode(',', (string) ($this->option('keywords') ?: implode(',', (array) ($cfg['keywords'] ?? [])))))));
        $brand = mb_strtolower(preg_replace('/\s*&.*$/', '', (string) config('brand.name')) ?: 'gs construction');
        if ($lat === 0.0 || $keywords === []) {
            $this->error('seo.map_pack needs center_lat/center_lng and keywords.');

            return self::FAILURE;
        }

        $points = self::grid($lat, $lng, $n, $radius);
        $estimate = count($points) * count($keywords) * 0.002;
        $balance = $dfs->balance();
        $this->line(sprintf('%d×%d grid (%d points, %s mi radius) × %d keywords — estimated $%.2f · balance %s', $n, $n, count($points), $radius, count($keywords), $estimate, $balance === null ? '?' : '$' . number_format($balance, 2)));
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if ($estimate > (float) $this->option('budget') || ($balance !== null && $balance < $estimate)) {
            $this->error('Estimate exceeds --budget or balance.');

            return self::FAILURE;
        }

        $siteId = \App\Models\Site::current()?->id;
        $today = now();
        foreach ($keywords as $keyword) {
            $grid = [];
            $competitors = [];
            $found = 0;
            $rankSum = 0;
            $trpSum = 0;
            $top3 = 0;
            foreach ($points as [$plat, $plng]) {
                if ($dfs->spent() >= (float) $this->option('budget')) {
                    $this->warn('Budget reached mid-grid.');
                    break 2;
                }
                $results = $dfs->mapsResults($keyword, $plat, $plng);
                $rank = false;
                foreach ($results as $r) {
                    $pid = (string) ($r['place_id'] ?? '');
                    $isUs = str_contains(mb_strtolower((string) $r['title']), $brand);
                    if ($isUs && $rank === false) {
                        $rank = (int) $r['rank'];
                    }
                    if ($pid === '' || $isUs) {
                        continue;
                    }
                    $c = $competitors[$pid] ?? ['place_id' => $pid, 'name' => $r['title'], 'url' => $r['url'], 'rating' => $r['rating'], 'reviews' => $r['reviews'], 'claimed' => $r['claimed'], 'categories' => $r['categories'], 'pack' => 0, 'seen' => 0, 'best' => 99];
                    $c['seen']++;
                    $c['best'] = min($c['best'], (int) $r['rank']);
                    if ((int) $r['rank'] <= 3) {
                        $c['pack']++;
                    }
                    $competitors[$pid] = $c;
                }
                $grid[] = ['lat' => $plat, 'lng' => $plng, 'rank' => $rank];
                if ($rank !== false) {
                    $found++;
                    $rankSum += $rank;
                    $trpSum += $rank;
                    if ($rank <= 3) {
                        $top3++;
                    }
                } else {
                    $trpSum += 21;
                }
            }
            usort($competitors, fn ($a, $b) => [$b['pack'], $b['seen']] <=> [$a['pack'], $a['seen']]);
            $competitors = array_slice(array_values($competitors), 0, 25);
            $pointsN = count($grid);
            $scanId = 'grid-' . $today->format('Ymd') . '-' . substr(md5($keyword), 0, 8);
            $detail = [
                'source' => 'dataforseo',
                'grid' => $grid,
                'competitors' => $competitors,
                'pack_leaders' => array_map(fn ($c) => ['business' => $c['name'], 'appearances' => $c['pack']], array_slice($competitors, 0, 8)),
                'found' => $found,
                'points_total' => $pointsN,
                'center' => ['lat' => $lat, 'lng' => $lng],
                'radius' => $radius . 'mi',
            ];
            Tenancy::table('map_pack_scans')->updateOrInsert(
                ['scan_id' => $scanId],
                [
                    'site_id' => $siteId, 'keyword' => $keyword, 'platform' => 'google', 'scanned_at' => $today,
                    'arp' => $found ? round($rankSum / $found, 2) : 21, 'atrp' => $pointsN ? round($trpSum / $pointsN, 2) : 21,
                    'solv' => $pointsN ? round(100 * $top3 / $pointsN, 2) : 0, 'grid_points' => $n, 'in_top3' => $top3,
                    'raw' => json_encode(['keyword' => $keyword, 'detail' => $detail]),
                    'updated_at' => now(), 'created_at' => now(),
                ]
            );
            foreach ($competitors as $c) {
                Tenancy::table('map_pack_competitors')->updateOrInsert(
                    ['site_id' => $siteId, 'place_id' => $c['place_id'], 'keyword' => mb_substr($keyword, 0, 191)],
                    [
                        'name' => mb_substr((string) $c['name'], 0, 191),
                        'url' => $c['url'] ? mb_substr((string) $c['url'], 0, 500) : null,
                        'host' => $c['url'] ? mb_substr((string) preg_replace('/^www\./', '', (string) parse_url((string) $c['url'], PHP_URL_HOST)), 0, 191) : null,
                        'rating' => $c['rating'], 'reviews' => $c['reviews'], 'claimed' => $c['claimed'],
                        'categories' => $c['categories'] ? json_encode($c['categories']) : null,
                        'scan_id' => $scanId, 'scanned_at' => $today,
                        'pack_points' => $c['pack'], 'seen_points' => $c['seen'], 'best_rank' => $c['best'] < 99 ? $c['best'] : null,
                        'updated_at' => now(), 'created_at' => now(),
                    ]
                );
            }
            $this->line(sprintf('  %-24s in pack %3d/%d · found %3d · ARP %s · leader %s', $keyword, $top3, $pointsN, $found, $found ? round($rankSum / $found, 1) : '—', $competitors[0]['name'] ?? '—'));
        }
        Cache::forget(Tenancy::cacheKey('seo_reports_map_pack_v1'));
        $this->info(sprintf('Done. Spent $%.3f.', $dfs->spent()));

        return self::SUCCESS;
    }

    /** N×N points, evenly spaced, the outermost `radius` miles from the center along each axis. */
    public static function grid(float $lat, float $lng, int $n, float $radiusMiles): array
    {
        $milesPerDegLat = 69.0;
        $milesPerDegLng = 69.0 * cos(deg2rad($lat));
        $points = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $dy = ($n === 1 ? 0 : (-$radiusMiles + 2 * $radiusMiles * $i / ($n - 1)));
                $dx = ($n === 1 ? 0 : (-$radiusMiles + 2 * $radiusMiles * $j / ($n - 1)));
                $points[] = [round($lat + $dy / $milesPerDegLat, 6), round($lng + $dx / $milesPerDegLng, 6)];
            }
        }

        return $points;
    }
}
