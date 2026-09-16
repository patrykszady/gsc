<?php

namespace App\Console\Commands;

use App\Models\SeoRankSnapshot;
use App\Services\DataForSeoService;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Weekly rank snapshots for the tracked queries in config/seo.php, derived
 * from synced Search Console data (gsc_query_metrics). This is the position
 * Google itself reports for queries with impressions — no scraping, no paid
 * SERP API. Queries with zero recent impressions record a null position
 * (we're not surfacing for them).
 *
 * Historical rows with engine google/google_maps in seo_rank_snapshots come
 * from a retired scraping integration and remain for trend continuity.
 */
class TrackRankings extends Command
{
    protected $signature = 'seo:track-rankings
        {--query= : Run only queries containing this substring}
        {--dry-run : Compute but do not persist}
        {--budget= : Max USD for the DataForSEO SERP pass this run (default config seo.rank_tracker.budget)}';

    protected $description = 'Snapshot Google rankings for tracked queries from synced Search Console data.';

    public function handle(): int
    {
        if (! Schema::hasTable('gsc_query_metrics')) {
            $this->error('gsc_query_metrics table missing — run seo:gsc-sync first.');

            return self::FAILURE;
        }

        $filter = (string) ($this->option('query') ?? '');
        $dryRun = (bool) $this->option('dry-run');

        $since = Carbon::today()->subDays(6)->toDateString();
        $found = $missed = 0;

        foreach ((array) config('seo.rank_tracker.web_queries', []) as $cfg) {
            $query = (string) $cfg['q'];
            if ($filter !== '' && stripos($query, $filter) === false) {
                continue;
            }

            // Tracked queries carry an " IL" suffix real searchers rarely type —
            // match both variants and let the impression-weighted union decide.
            $lower = strtolower($query);
            $variants = array_unique([$lower, preg_replace('/\s+il$/', '', $lower)]);

            $row = Tenancy::table('gsc_query_metrics')
                ->where('date', '>=', $since)
                ->whereIn('query', $variants)
                ->selectRaw('SUM(position * impressions) / NULLIF(SUM(impressions), 0) pos, SUM(impressions) impr, SUM(clicks) clk')
                ->first();

            $impressions = (int) ($row->impr ?? 0);
            $pos = $impressions > 0 ? (int) round((float) $row->pos) : null;

            $previous = SeoRankSnapshot::query()
                ->forQuery('gsc', $query, null)
                ->latest('id')
                ->first();

            if (! $dryRun) {
                SeoRankSnapshot::create([
                    'engine' => 'gsc',
                    'query' => $query,
                    'location' => null,
                    'city_slug' => (string) ($cfg['city_slug'] ?? '') ?: null,
                    'gsc_position' => $pos,
                    'gsc_match_title' => null,
                    'result_count' => null,
                    'top_results' => [],
                    'meta' => [
                        'source' => 'gsc_query_metrics',
                        'window_days' => 7,
                        'impressions' => $impressions,
                        'clicks' => (int) ($row->clk ?? 0),
                    ],
                    'fetched_at' => Carbon::now(),
                ]);
            }

            $pos !== null ? $found++ : $missed++;

            $delta = '';
            if ($previous && $previous->gsc_position !== null && $pos !== null) {
                $diff = $previous->gsc_position - $pos;
                $delta = $diff > 0 ? " (▲{$diff})" : ($diff < 0 ? ' (▼'.abs($diff).')' : ' (=)');
            }

            $this->line(sprintf(
                '  [gsc] %-50s %s%s  impr=%d',
                Str::limit($query, 50, ''),
                $pos === null ? '—' : "#{$pos}",
                $delta,
                $impressions
            ));
        }

        $this->newLine();
        $this->info("Done. found={$found}  no-data={$missed}".($dryRun ? '  (dry-run)' : ''));

        // Real SERP observations via DataForSEO, when credentials exist.
        // engine='google' rows resume the series the retired scraper left off
        // (last real observation before this: 2026-07-06). The GSC-derived
        // pass above stays: two instruments, clearly labeled by engine.
        $dfs = app(DataForSeoService::class);
        if (! $dfs->isConfigured()) {
            $this->comment('DataForSEO not configured (DATAFORSEO_LOGIN/PASSWORD) — real SERP pass skipped.');

            return self::SUCCESS;
        }

        $queriesToRun = array_values(array_filter(
            (array) config('seo.rank_tracker.web_queries', []),
            fn ($cfg) => $filter === '' || stripos((string) $cfg['q'], $filter) !== false
        ));

        if ($queriesToRun === []) {
            $this->comment('No tracked queries match --query — DataForSEO pass skipped.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('DataForSEO SERP checks (engine=google):');

        // A1: no more unguarded spend. `--budget` (or config
        // seo.rank_tracker.budget, $1/run default) caps this pass; the
        // estimate uses the active serp_mode's per-check price, and an
        // unknown balance means we refuse to spend at all (FAIL CLOSED)
        // rather than assume there's room — see SeoMapPackGrid for the
        // same estimate -> balance() -> spent() pattern.
        $mode = (string) config('seo.rank_tracker.serp_mode', 'standard');
        $pricePerQuery = $mode === 'standard' ? 0.0006 : 0.002;
        $estimate = round(count($queriesToRun) * $pricePerQuery, 4);
        $budget = (float) ($this->option('budget') ?? config('seo.rank_tracker.budget', 1.0));
        $balance = $dfs->balance();

        $this->line(sprintf(
            '%d queries via %s — estimated $%.4f · balance %s · budget $%.2f',
            count($queriesToRun),
            $mode,
            $estimate,
            $balance === null ? '?' : '$'.number_format($balance, 2),
            $budget
        ));

        if ($balance === null) {
            $this->error('DataForSEO balance check failed — refusing to spend without a known balance.');

            return self::FAILURE;
        }
        if ($estimate > $budget || $balance < $estimate) {
            $this->error('Estimate exceeds --budget or balance — DataForSEO pass skipped.');

            return self::FAILURE;
        }

        // Standard queue first (~$0.0006/check, one task_post for the whole
        // batch — see DataForSeoService::googleOrganicStandardBatch);
        // automatic fallback to the per-query Live endpoint when the queue
        // path itself errors.
        $observations = []; // index into $queriesToRun => obs array|null
        $usedStandard = false;

        if ($mode === 'standard') {
            $batch = $dfs->googleOrganicStandardBatch(array_map(fn ($cfg) => [
                'keyword' => (string) $cfg['q'],
                'location_name' => (string) ($cfg['location'] ?? 'Chicago,Illinois,United States'),
                'depth' => 100,
            ], $queriesToRun));

            if ($batch !== null) {
                $usedStandard = true;
                foreach ($queriesToRun as $i => $cfg) {
                    $result = $batch[$i] ?? null;
                    $observations[$i] = is_array($result)
                        ? DataForSeoService::parseOrganicResult($result, 'gs.construction')
                        : null;
                }
            } else {
                $this->warn('Standard queue failed to post — falling back to Live for this run: '.($dfs->getLastError() ?? 'unknown error'));
            }
        }

        if (! $usedStandard) {
            foreach ($queriesToRun as $i => $cfg) {
                // A1: mid-loop cutoff. The Standard batch above is one
                // atomic call the estimate/balance check above already
                // gated; this per-query loop is the one that can actually
                // overspend mid-run, so it's checked before every unit of
                // paid work (same pattern as SeoMapPackGrid's grid loop).
                if ($dfs->spent() >= $budget) {
                    $this->warn('Budget reached mid-run — remaining queries skipped.');
                    break;
                }
                $observations[$i] = $dfs->googleOrganicPosition((string) $cfg['q'], 'gs.construction', (string) ($cfg['location'] ?? 'Chicago,Illinois,United States'));
                usleep(400_000); // stay polite on the live endpoint
            }
        }

        $sourceLabel = $usedStandard ? 'dataforseo_standard_advanced' : 'dataforseo_live_advanced';
        $rf = $rm = $failed = $attempted = 0;

        foreach ($queriesToRun as $i => $cfg) {
            if (! array_key_exists($i, $observations)) {
                continue; // budget cutoff stopped the live loop before this one ran
            }
            $attempted++;
            $query = (string) $cfg['q'];
            $obs = $observations[$i];

            if ($obs === null) {
                // A2: 23–39% of weekly queries were silently dropped here
                // (`continue` on a null observation, console warning only).
                // A fetch_failed row keeps "DataForSEO never answered"
                // distinguishable from "answered: not ranking" (gsc_position
                // null, no flag) instead of vanishing from the series.
                $failed++;
                $error = $dfs->getLastError() ?? 'failed';
                $this->warn("  [google] {$query}: {$error}");

                if (! $dryRun) {
                    SeoRankSnapshot::create([
                        'engine' => 'google',
                        'query' => $query,
                        'location' => (string) ($cfg['location'] ?? '') ?: null,
                        'city_slug' => (string) ($cfg['city_slug'] ?? '') ?: null,
                        'gsc_position' => null,
                        'gsc_match_title' => null,
                        'result_count' => null,
                        'top_results' => [],
                        'meta' => ['source' => $sourceLabel, 'fetch_failed' => true, 'error' => $error],
                        'fetched_at' => Carbon::now(),
                    ]);
                }

                continue;
            }

            if (! $dryRun) {
                SeoRankSnapshot::create([
                    'engine' => 'google',
                    'query' => $query,
                    // A3: the DataForSEO engine now checks from the query's
                    // own suburb instead of a hardcoded Chicago vantage.
                    'location' => (string) ($cfg['location'] ?? '') ?: null,
                    'city_slug' => (string) ($cfg['city_slug'] ?? '') ?: null,
                    'gsc_position' => $obs['position'],
                    'gsc_match_title' => null,
                    'result_count' => null,
                    'top_results' => array_slice($obs['top_domains'], 0, 10),
                    'meta' => [
                        'source' => $sourceLabel,
                        'matched_url' => $obs['url'],
                        'local_pack_present' => $obs['local_pack'],
                    ],
                    'fetched_at' => Carbon::now(),
                ]);
            }

            $obs['position'] !== null ? $rf++ : $rm++;
            $this->line(sprintf(
                '  [google] %-48s %s%s',
                Str::limit($query, 48, ''),
                $obs['position'] === null ? 'not in top 100' : '#'.$obs['position'],
                $obs['local_pack'] ? '  (local pack on SERP)' : ''
            ));
        }

        $this->info("Real SERP done. ranked={$rf}  not-in-top-100={$rm}  failed={$failed}".($dryRun ? '  (dry-run)' : ''));

        // A2: every query DataForSEO failed to answer this run is not a
        // quiet skip — it's a run worth alerting on.
        if ($attempted > 0 && $failed === $attempted) {
            $this->error('Every DataForSEO SERP check failed this run.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
