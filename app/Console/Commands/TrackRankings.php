<?php

namespace App\Console\Commands;

use App\Models\SeoRankSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        {--dry-run : Compute but do not persist}';

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

            $row = DB::table('gsc_query_metrics')
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
                $delta = $diff > 0 ? " (▲{$diff})" : ($diff < 0 ? ' (▼' . abs($diff) . ')' : ' (=)');
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
        $this->info("Done. found={$found}  no-data={$missed}" . ($dryRun ? '  (dry-run)' : ''));

        return self::SUCCESS;
    }
}
