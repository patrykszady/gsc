<?php

namespace App\Console\Commands;

use App\Models\SeoRankSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TrackRankings extends Command
{
    protected $signature = 'seo:track-rankings
        {--engine=both : google | google_maps | both}
        {--query= : Run only queries containing this substring}
        {--dry-run : Hit SerpApi but do not persist}
        {--show : Print a comparison vs. last snapshot}';

    protected $description = 'Snapshot Google + Google Maps rankings via SerpApi for the queries defined in config/seo.php and persist position data for trend tracking.';

    public function handle(): int
    {
        $apiKey = (string) config('services.serpapi.api_key', '');
        if ($apiKey === '') {
            $this->error('SERPAPI_API_KEY (or SERPAPI_KEY) is not set.');
            return self::FAILURE;
        }

        $engine = $this->option('engine');
        $filter = (string) ($this->option('query') ?? '');
        $dryRun = (bool) $this->option('dry-run');
        $show = (bool) $this->option('show');

        $patterns = array_map('strtolower', (array) config('seo.rank_tracker.identity_patterns', []));
        $competitors = array_map(
            fn ($pats) => array_map('strtolower', (array) $pats),
            (array) config('seo.rank_tracker.competitor_patterns', [])
        );
        $topN = (int) config('seo.rank_tracker.store_top_n', 10);

        $totals = ['google' => 0, 'google_maps' => 0, 'found' => 0, 'not_found' => 0, 'errors' => 0];

        if (in_array($engine, ['both', 'google'], true)) {
            foreach ((array) config('seo.rank_tracker.web_queries', []) as $cfg) {
                if ($filter !== '' && stripos($cfg['q'], $filter) === false) continue;
                $this->runOne('google', $cfg, $apiKey, $patterns, $competitors, $topN, $dryRun, $show, $totals);
                usleep(800_000);
            }
        }

        if (in_array($engine, ['both', 'google_maps'], true)) {
            foreach ((array) config('seo.rank_tracker.maps_queries', []) as $cfg) {
                if ($filter !== '' && stripos($cfg['q'], $filter) === false) continue;
                $this->runOne('google_maps', $cfg, $apiKey, $patterns, $competitors, $topN, $dryRun, $show, $totals);
                usleep(800_000);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. google=%d  google_maps=%d  found=%d  not_found=%d  errors=%d%s',
            $totals['google'], $totals['google_maps'], $totals['found'], $totals['not_found'], $totals['errors'],
            $dryRun ? '  (dry-run)' : ''
        ));

        return self::SUCCESS;
    }

    /**
     * @param array<int,string> $patterns
     * @param array<string,array<int,string>> $competitors
     * @param array<string,int> $totals
     */
    protected function runOne(string $engine, array $cfg, string $apiKey, array $patterns, array $competitors, int $topN, bool $dryRun, bool $show, array &$totals): void
    {
        $query = (string) $cfg['q'];
        $location = $engine === 'google' ? (string) ($cfg['location'] ?? '') : (string) ($cfg['ll'] ?? '');
        $citySlug = (string) ($cfg['city_slug'] ?? '') ?: null;

        $params = [
            'engine'  => $engine,
            'q'       => $query,
            'hl'      => 'en',
            'gl'      => 'us',
            'api_key' => $apiKey,
        ];
        if ($engine === 'google') {
            $params['location'] = $location;
            $params['num'] = 20;
        } else {
            $params['ll'] = $location;
            $params['type'] = 'search';
        }

        try {
            $resp = Http::timeout(45)->get('https://serpapi.com/search.json', $params);
        } catch (\Throwable $e) {
            $this->error("[{$engine}] {$query} — HTTP error: " . $e->getMessage());
            $totals['errors']++;
            return;
        }

        if (! $resp->successful()) {
            $this->error("[{$engine}] {$query} — HTTP " . $resp->status());
            $totals['errors']++;
            return;
        }
        $json = $resp->json();
        if (! empty($json['error'])) {
            $this->error("[{$engine}] {$query} — SerpApi: " . $json['error']);
            $totals['errors']++;
            return;
        }

        [$results, $resultCount] = $this->extractResults($engine, $json);
        [$gscPos, $gscTitle] = $this->findUs($results, $patterns, $engine);

        $top = array_slice(array_map(function (array $r) use ($engine) {
            return [
                'position' => $r['position'] ?? null,
                'title'    => $r['title'] ?? null,
                'rating'   => $r['rating'] ?? null,
                'reviews'  => $r['reviews'] ?? null,
                'address'  => $r['address'] ?? null,
                'host'     => $engine === 'google' ? ($r['host'] ?? null) : null,
                'link'     => $engine === 'google' ? ($r['link'] ?? null) : null,
            ];
        }, $results), 0, $topN);

        $meta = [
            'search_id' => $json['search_metadata']['id'] ?? null,
            'ads_count' => is_array($json['ads'] ?? null) ? count($json['ads']) : 0,
            'competitors' => $this->findCompetitors($results, $competitors, $engine),
        ];

        $previous = SeoRankSnapshot::query()
            ->forQuery($engine, $query, $location ?: null)
            ->latest('id')
            ->first();

        if (! $dryRun) {
            SeoRankSnapshot::create([
                'engine' => $engine,
                'query' => $query,
                'location' => $location ?: null,
                'city_slug' => $citySlug,
                'gsc_position' => $gscPos,
                'gsc_match_title' => $gscTitle,
                'result_count' => $resultCount,
                'top_results' => $top,
                'meta' => $meta,
                'fetched_at' => Carbon::now(),
            ]);
        }

        if ($gscPos !== null) {
            $totals['found']++;
        } else {
            $totals['not_found']++;
        }
        $totals[$engine]++;

        $delta = '';
        if ($previous && $previous->gsc_position !== null && $gscPos !== null) {
            $diff = $previous->gsc_position - $gscPos;
            if ($diff > 0) $delta = " (▲{$diff})";
            elseif ($diff < 0) $delta = " (▼" . abs($diff) . ")";
            else $delta = ' (=)';
        } elseif ($previous && $previous->gsc_position === null && $gscPos !== null) {
            $delta = ' (NEW)';
        } elseif ($previous && $previous->gsc_position !== null && $gscPos === null) {
            $delta = ' (DROPPED)';
        }

        $posLabel = $gscPos === null ? '—' : "#{$gscPos}";
        $titleLabel = $gscTitle ? " [{$gscTitle}]" : '';
        $this->line(sprintf('  [%-11s] %-50s %s%s%s', $engine, Str::limit($query, 50, ''), $posLabel, $delta, $titleLabel));

        if ($show) {
            foreach (array_slice($top, 0, 5) as $r) {
                $rt = $r['rating'] ?? '-';
                $rv = $r['reviews'] ?? 0;
                $extra = $engine === 'google_maps'
                    ? " ★{$rt} ({$rv})  " . Str::limit((string) ($r['address'] ?? ''), 35, '')
                    : '  ' . Str::limit((string) ($r['host'] ?? ''), 40, '');
                $this->line(sprintf('       #%-2s  %-46s%s', $r['position'] ?? '?', Str::limit((string) ($r['title'] ?? ''), 46, ''), $extra));
            }
        }
    }

    /**
     * @return array{0: array<int,array<string,mixed>>, 1: int}
     */
    protected function extractResults(string $engine, array $json): array
    {
        if ($engine === 'google_maps') {
            $places = $json['local_results'] ?? [];
            $out = [];
            foreach ($places as $i => $p) {
                $out[] = [
                    'position' => $p['position'] ?? ($i + 1),
                    'title'    => $p['title'] ?? null,
                    'rating'   => $p['rating'] ?? null,
                    'reviews'  => $p['reviews'] ?? null,
                    'address'  => $p['address'] ?? null,
                ];
            }
            return [$out, count($places)];
        }

        // engine = google
        $out = [];

        // 1) Local pack first (these are what searchers see prominently)
        $local = $json['local_results']['places'] ?? ($json['local_results'] ?? []);
        if (is_array($local) && isset($local[0])) {
            foreach ($local as $i => $p) {
                $out[] = [
                    'position' => 'L' . (($p['position'] ?? ($i + 1))),
                    'title'    => $p['title'] ?? null,
                    'rating'   => $p['rating'] ?? null,
                    'reviews'  => $p['reviews'] ?? null,
                    'address'  => $p['address'] ?? null,
                    'host'     => null,
                    'link'     => null,
                ];
            }
        }

        // 2) Organic results
        foreach (($json['organic_results'] ?? []) as $r) {
            $link = (string) ($r['link'] ?? '');
            $out[] = [
                'position' => $r['position'] ?? null,
                'title'    => $r['title'] ?? null,
                'host'     => $link ? (parse_url($link, PHP_URL_HOST) ?: null) : null,
                'link'     => $link ?: null,
            ];
        }

        return [$out, count($json['organic_results'] ?? [])];
    }

    /**
     * @param array<int,array<string,mixed>> $results
     * @param array<int,string>              $patterns
     * @return array{0: int|null, 1: string|null}
     */
    protected function findUs(array $results, array $patterns, string $engine): array
    {
        foreach ($results as $r) {
            $hay = strtolower(($r['title'] ?? '') . ' ' . ($r['host'] ?? ''));
            foreach ($patterns as $p) {
                if ($p !== '' && str_contains($hay, $p)) {
                    $pos = $r['position'] ?? null;
                    // For local-pack we use "L1, L2…" — store as 1..3 (lower = better).
                    if (is_string($pos) && str_starts_with($pos, 'L')) {
                        $pos = (int) substr($pos, 1);
                    }
                    return [is_numeric($pos) ? (int) $pos : null, $r['title'] ?? null];
                }
            }
        }
        return [null, null];
    }

    /**
     * Find best (lowest numeric) position for each tracked competitor in this SERP.
     *
     * @param array<int,array<string,mixed>> $results
     * @param array<string,array<int,string>> $competitors
     * @return array<string,array{position:int|null,title:?string}>
     */
    protected function findCompetitors(array $results, array $competitors, string $engine): array
    {
        $out = [];
        foreach ($competitors as $key => $patterns) {
            $best = null;
            $title = null;
            foreach ($results as $r) {
                $hay = strtolower(($r['title'] ?? '') . ' ' . ($r['host'] ?? ''));
                foreach ($patterns as $p) {
                    if ($p === '' || ! str_contains($hay, $p)) {
                        continue;
                    }
                    $pos = $r['position'] ?? null;
                    if (is_string($pos) && str_starts_with($pos, 'L')) {
                        $pos = (int) substr($pos, 1);
                    }
                    if (! is_numeric($pos)) {
                        continue;
                    }
                    $posInt = (int) $pos;
                    if ($best === null || $posInt < $best) {
                        $best = $posInt;
                        $title = $r['title'] ?? null;
                    }
                }
            }
            $out[$key] = ['position' => $best, 'title' => $title];
        }
        return $out;
    }
}
