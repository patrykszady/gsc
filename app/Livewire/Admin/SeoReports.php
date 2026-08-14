<?php

namespace App\Livewire\Admin;

use App\Models\GscCoverageState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Read-only dashboard that surfaces the weekly SEO markdown reports produced
 * by the seo:* scheduled commands. Operators can also re-run a report
 * on-demand (synchronous artisan call; intended for small/fast jobs).
 */
#[Layout('components.layouts.admin')]
#[Title('SEO Reports')]
class SeoReports extends Component
{
    public ?string $active = null;

    public ?string $flash = null;

    #[Url(as: 'range', keep: true)]
    public int $trendDays = 14;

    #[Url(as: 'metric', keep: true)]
    public string $trendMetric = 'clicks';

    #[Url(as: 'combined', keep: true)]
    public int $trendCombined = 1;

    #[Url(as: 'tspan', keep: true)]
    public int $topDays = 28;

    #[Url(as: 'qsort', keep: true)]
    public string $topQueriesSort = 'clicks';

    #[Url(as: 'qdir', keep: true)]
    public string $topQueriesDir = 'desc';

    #[Url(as: 'psort', keep: true)]
    public string $topPagesSort = 'clicks';

    #[Url(as: 'pdir', keep: true)]
    public string $topPagesDir = 'desc';

    /** @var array<int,int> */
    protected array $topDayOptions = [7, 28, 90];

    /** @var array<int,string> */
    protected array $sortableColumns = ['clicks', 'impressions', 'ctr', 'position'];

    /**
     * Report registry, loaded from config/seo-reports.php every request via
     * boot() (a protected non-Livewire property resets between requests, so
     * mount() alone would leave it empty on interaction round-trips).
     *
     * @var array<string, array{label:string, command:string, description:string}>
     */
    protected array $reports = [];

    public function boot(): void
    {
        $this->reports = (array) config('seo-reports.reports', []);
    }

    public function mount(?string $report = null): void
    {
        // Self-heal stale reports on view: the scheduler keeps these fresh in
        // production, but on a machine without `schedule:work` (local dev) the
        // files just age. Queue regens — throttled per report — so the page
        // converges to fresh on its own wherever a queue worker runs.
        foreach ($this->reports as $key => $meta) {
            $path = \App\Support\SeoStorage::path("reports/{$key}.md");
            $mtime = Storage::disk('local')->exists($path) ? Storage::disk('local')->lastModified($path) : null;
            $isStale = $mtime === null || $mtime < now()->subHours(26)->getTimestamp();
            if ($isStale && Cache::add(\App\Support\Tenancy::cacheKey("report_regen_queued:{$key}"), 1, now()->addHours(6))) {
                \App\Jobs\RunSeoChannelSyncJob::dispatch($meta['command']);
            }
        }

        if ($report !== null && isset($this->reports[$report])) {
            $this->active = $report;
        }

        if (! in_array($this->trendDays, [7, 14, 30], true)) {
            $this->trendDays = 14;
        }

        if (! in_array($this->trendMetric, ['clicks', 'impressions'], true)) {
            $this->trendMetric = 'clicks';
        }

        $this->trendCombined = $this->trendCombined === 1 ? 1 : 0;

        if (! in_array($this->topDays, $this->topDayOptions, true)) {
            $this->topDays = 28;
        }

        if (! in_array($this->topQueriesSort, ['query', ...$this->sortableColumns], true)) {
            $this->topQueriesSort = 'clicks';
        }

        if (! in_array($this->topPagesSort, ['page', ...$this->sortableColumns], true)) {
            $this->topPagesSort = 'clicks';
        }

        $this->topQueriesDir = $this->topQueriesDir === 'asc' ? 'asc' : 'desc';
        $this->topPagesDir = $this->topPagesDir === 'asc' ? 'asc' : 'desc';
    }

    public function open(string $key): void
    {
        if (! isset($this->reports[$key])) {
            return;
        }
        $this->active = $key;
    }

    public function regenerate(string $key): void
    {
        if (! isset($this->reports[$key])) {
            return;
        }
        // Synchronous re-run; these commands are read-mostly and fast.
        try {
            Artisan::call($this->reports[$key]['command']);
            $this->flash = $this->reports[$key]['label'] . ' regenerated.';
        } catch (\Throwable $e) {
            $this->flash = 'Failed to regenerate: ' . $e->getMessage();
        }
        $this->active = $key;
        Cache::forget(\App\Support\Tenancy::cacheKey('admin.seo-reports.health-snapshot'));
        Cache::forget($this->searchSnapshotCacheKey());
        // Bust the computed cache so the file list / body re-read from disk.
        unset($this->files, $this->reportStats, $this->healthSnapshot, $this->searchSnapshot, $this->activeHtml);
    }

    public function setTrendDays(int $days): void
    {
        if (! in_array($days, [7, 14, 30], true)) {
            return;
        }

        if ($this->trendDays === $days) {
            return;
        }

        Cache::forget($this->searchSnapshotCacheKey());
        $this->trendDays = $days;
        unset($this->searchSnapshot);
    }

    public function setTrendMetric(string $metric): void
    {
        if (! in_array($metric, ['clicks', 'impressions'], true)) {
            return;
        }

        if ($this->trendMetric === $metric) {
            return;
        }

        $this->trendMetric = $metric;
    }

    public function toggleTrendCombined(): void
    {
        $this->trendCombined = $this->trendCombined === 1 ? 0 : 1;
    }

    public function setTopDays(int $days): void
    {
        if (! in_array($days, $this->topDayOptions, true) || $this->topDays === $days) {
            return;
        }

        $this->topDays = $days;
        unset($this->topQueries, $this->topPages);
    }

    public function sortTopQueries(string $column): void
    {
        if (! in_array($column, ['query', ...$this->sortableColumns], true)) {
            return;
        }

        if ($this->topQueriesSort === $column) {
            $this->topQueriesDir = $this->topQueriesDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->topQueriesSort = $column;
            // Text column defaults to A→Z; numeric columns default to highest first.
            $this->topQueriesDir = $column === 'query' ? 'asc' : 'desc';
        }

        unset($this->topQueries);
    }

    public function sortTopPages(string $column): void
    {
        if (! in_array($column, ['page', ...$this->sortableColumns], true)) {
            return;
        }

        if ($this->topPagesSort === $column) {
            $this->topPagesDir = $this->topPagesDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->topPagesSort = $column;
            $this->topPagesDir = $column === 'page' ? 'asc' : 'desc';
        }

        unset($this->topPages);
    }

    /**
    * @return array<int, array{key:string,label:string,description:string,command:string,exists:bool,size:?int,mtime:?\Carbon\CarbonInterface,age:?string,age_hours:?int,freshness_pct:int,status:string}>
     */
    #[Computed]
    public function files(): array
    {
        $disk = Storage::disk('local');
        $out = [];
        foreach ($this->reports as $key => $meta) {
            $path = \App\Support\SeoStorage::path("reports/{$key}.md");
            $exists = $disk->exists($path);
            $size = $exists ? $disk->size($path) : null;
            $mtimeTs = $exists ? $disk->lastModified($path) : null;
            $mtime = $mtimeTs ? Carbon::createFromTimestamp($mtimeTs) : null;
            // abs(): Carbon 3 signed diffs return NEGATIVE hours for past
            // mtimes, which made month-old files pass "<= 24" as "fresh".
            $ageHours = $mtime ? (int) abs(now()->diffInHours($mtime)) : null;
            $freshnessPct = $ageHours === null ? 0 : max(0, 100 - (int) round(min($ageHours, 72) / 72 * 100));
            $status = $ageHours === null ? 'missing' : ($ageHours <= 24 ? 'fresh' : 'stale');
            $out[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'command' => $meta['command'],
                'exists' => $exists,
                'size' => $size,
                'mtime' => $mtime,
                'age' => $mtime?->diffForHumans(),
                'age_hours' => $ageHours,
                'freshness_pct' => $freshnessPct,
                'status' => $status,
            ];
        }
        return $out;
    }

    /**
     * @return array{total:int,generated:int,fresh:int,stale:int,missing:int,updated_today:int,last_update:?string}
     */
    #[Computed]
    public function reportStats(): array
    {
        $files = collect($this->files);
        $generated = $files->where('exists', true);

        return [
            'total' => $files->count(),
            'generated' => $generated->count(),
            'fresh' => $files->where('status', 'fresh')->count(),
            'stale' => $files->where('status', 'stale')->count(),
            'missing' => $files->where('status', 'missing')->count(),
            'updated_today' => $generated->filter(fn (array $f) => ($f['age_hours'] ?? 9999) < 24)->count(),
            'last_update' => $generated
                ->sortByDesc(fn (array $f) => $f['mtime']?->timestamp ?? 0)
                ->first()['age'] ?? null,
        ];
    }

    /**
     * @return array{
     *   channels:array<string,array{label:string,clicks:int,impressions:int,ctr:float,position:float,delta_clicks:float}>,
     *   daily_clicks:array<int,array{date:string,gsc:int,bing:int,combined:int}>,
     *   coverage:array{total:int,problem:int,forbidden:int,not_indexed:int,duplicate:int},
     *   rankings:array{tracked:int,top3:int,top10:int,top20:int,below20:int},
     *   action_items:array<int,string>
     * }
     */
    #[Computed]
    public function searchSnapshot(): array
    {
        return Cache::remember($this->searchSnapshotCacheKey(), now()->addMinutes(15), function (): array {
            $today = Carbon::today();
            $currStart = $today->copy()->subDays(6);
            $prevStart = $today->copy()->subDays(13);
            $prevEnd = $today->copy()->subDays(7);

            $channels = [
                'gsc' => [
                    'label' => 'Google Search Console',
                    'clicks' => 0,
                    'impressions' => 0,
                    'ctr' => 0.0,
                    'position' => 0.0,
                    'delta_clicks' => 0.0,
                ],
                'bing' => [
                    'label' => 'Bing Webmaster',
                    'clicks' => 0,
                    'impressions' => 0,
                    'ctr' => 0.0,
                    'position' => 0.0,
                    'delta_clicks' => 0.0,
                ],
                'gbp' => [
                    'label' => 'Google Business Profile',
                    'clicks' => 0,
                    'impressions' => 0,
                    'ctr' => 0.0,
                    'position' => 0.0,
                    'delta_clicks' => 0.0,
                ],
            ];

            if (Schema::hasTable('gsc_query_metrics')) {
                // Prefer true site-wide daily totals (date-dimension sync) which
                // include anonymized-query clicks the query-dimension table drops.
                $hasDailyTotals = Schema::hasTable('gsc_daily_totals')
                    && \App\Support\Tenancy::table('gsc_daily_totals')->whereBetween('date', [$prevStart->toDateString(), $today->toDateString()])->exists();

                $totalsTable = $hasDailyTotals ? 'gsc_daily_totals' : 'gsc_query_metrics';

                $curr = \App\Support\Tenancy::table($totalsTable)
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
                    ->first();

                $prev = \App\Support\Tenancy::table($totalsTable)
                    ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
                    ->selectRaw('SUM(clicks) as clicks')
                    ->first();

                $currClicks = (int) ($curr->clicks ?? 0);
                $currImpressions = (int) ($curr->impressions ?? 0);
                $channels['gsc'] = [
                    'label' => 'Google Search Console',
                    'clicks' => $currClicks,
                    'impressions' => $currImpressions,
                    'ctr' => $currImpressions > 0 ? round(($currClicks / $currImpressions) * 100, 2) : 0.0,
                    'position' => round((float) ($curr->position ?? 0), 2),
                    'delta_clicks' => $this->percentDelta($currClicks, (int) ($prev->clicks ?? 0)),
                ];
            }

            if (Schema::hasTable('bing_traffic_stats')) {
                // Position only exists in the per-query table; daily totals lack it.
                $positionRow = \App\Support\Tenancy::table('bing_traffic_stats')
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->selectRaw('AVG(position) as position')
                    ->first();

                // Prefer true site-wide daily totals (GetRankAndTrafficStats)
                // which include anonymized traffic the query table drops.
                $hasBingDailyTotals = Schema::hasTable('bing_daily_totals')
                    && \App\Support\Tenancy::table('bing_daily_totals')->whereBetween('date', [$prevStart->toDateString(), $today->toDateString()])->exists();

                $bingTotalsTable = $hasBingDailyTotals ? 'bing_daily_totals' : 'bing_traffic_stats';

                $curr = \App\Support\Tenancy::table($bingTotalsTable)
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions')
                    ->first();

                $prev = \App\Support\Tenancy::table($bingTotalsTable)
                    ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
                    ->selectRaw('SUM(clicks) as clicks')
                    ->first();

                $currClicks = (int) ($curr->clicks ?? 0);
                $currImpressions = (int) ($curr->impressions ?? 0);
                $channels['bing'] = [
                    'label' => 'Bing Webmaster',
                    'clicks' => $currClicks,
                    'impressions' => $currImpressions,
                    'ctr' => $currImpressions > 0 ? round(($currClicks / $currImpressions) * 100, 2) : 0.0,
                    'position' => round((float) ($positionRow->position ?? 0), 2),
                    'delta_clicks' => $this->percentDelta($currClicks, (int) ($prev->clicks ?? 0)),
                ];
            }

            if (Schema::hasTable('gbp_daily_metrics')) {
                $interactionMetrics = [
                    'WEBSITE_CLICKS',
                    'CALL_CLICKS',
                    'BUSINESS_DIRECTION_REQUESTS',
                    'BUSINESS_CONVERSATIONS',
                    'BUSINESS_BOOKINGS',
                ];

                $impressionMetrics = [
                    'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
                    'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
                    'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
                    'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
                ];

                $currClicks = (int) \App\Support\Tenancy::table('gbp_daily_metrics')
                    ->whereIn('metric', $interactionMetrics)
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->sum('value');

                $prevClicks = (int) \App\Support\Tenancy::table('gbp_daily_metrics')
                    ->whereIn('metric', $interactionMetrics)
                    ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
                    ->sum('value');

                $currImpressions = (int) \App\Support\Tenancy::table('gbp_daily_metrics')
                    ->whereIn('metric', $impressionMetrics)
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->sum('value');

                $channels['gbp'] = [
                    'label' => 'Google Business Profile',
                    'clicks' => $currClicks,
                    'impressions' => $currImpressions,
                    'ctr' => $currImpressions > 0 ? round(($currClicks / $currImpressions) * 100, 2) : 0.0,
                    'position' => 0.0,
                    'delta_clicks' => $this->percentDelta($currClicks, $prevClicks),
                ];
            }

            $dailyClicks = [];
            $hasDailyTotalsTable = Schema::hasTable('gsc_daily_totals');
            $hasBingDailyTotalsTable = Schema::hasTable('bing_daily_totals');
            for ($i = $this->trendDays - 1; $i >= 0; $i--) {
                $day = $today->copy()->subDays($i)->toDateString();

                // Prefer true daily totals; fall back to query-metric sums for
                // any day not yet captured by the date-dimension sync.
                $dailyTotal = $hasDailyTotalsTable
                    ? \App\Support\Tenancy::table('gsc_daily_totals')->whereDate('date', $day)->first()
                    : null;

                if ($dailyTotal) {
                    $gscDayClicks = (int) $dailyTotal->clicks;
                    $gscDayImpressions = (int) $dailyTotal->impressions;
                } else {
                    $gscDayClicks = Schema::hasTable('gsc_query_metrics')
                        ? (int) \App\Support\Tenancy::table('gsc_query_metrics')->whereDate('date', $day)->sum('clicks')
                        : 0;

                    $gscDayImpressions = Schema::hasTable('gsc_query_metrics')
                        ? (int) \App\Support\Tenancy::table('gsc_query_metrics')->whereDate('date', $day)->sum('impressions')
                        : 0;
                }

                // Prefer true Bing daily totals; fall back to query-stat sums.
                $bingDailyTotal = $hasBingDailyTotalsTable
                    ? \App\Support\Tenancy::table('bing_daily_totals')->whereDate('date', $day)->first()
                    : null;

                if ($bingDailyTotal) {
                    $bingDayClicks = (int) $bingDailyTotal->clicks;
                    $bingDayImpressions = (int) $bingDailyTotal->impressions;
                } else {
                    $bingDayClicks = Schema::hasTable('bing_traffic_stats')
                        ? (int) \App\Support\Tenancy::table('bing_traffic_stats')->whereDate('date', $day)->sum('clicks')
                        : 0;

                    $bingDayImpressions = Schema::hasTable('bing_traffic_stats')
                        ? (int) \App\Support\Tenancy::table('bing_traffic_stats')->whereDate('date', $day)->sum('impressions')
                        : 0;
                }

                $dailyClicks[] = [
                    'date' => Carbon::parse($day)->format('M j'),
                    'gsc_clicks' => $gscDayClicks,
                    'bing_clicks' => $bingDayClicks,
                    'combined_clicks' => $gscDayClicks + $bingDayClicks,
                    'gsc_impressions' => $gscDayImpressions,
                    'bing_impressions' => $bingDayImpressions,
                    'combined_impressions' => $gscDayImpressions + $bingDayImpressions,
                ];
            }

            $coverage = [
                'total' => 0,
                'problem' => 0,
                'forbidden' => 0,
                'not_indexed' => 0,
                'duplicate' => 0,
            ];

            if (Schema::hasTable('gsc_coverage_states')) {
                $coverage['total'] = (int) \App\Support\Tenancy::table('gsc_coverage_states')->count();
                $coverage['problem'] = (int) \App\Support\Tenancy::table('gsc_coverage_states')
                    ->where(function ($q) {
                        $q->where('verdict', '!=', 'PASS')->orWhereNull('verdict');
                    })
                    ->count();
                $coverage['forbidden'] = (int) \App\Support\Tenancy::table('gsc_coverage_states')
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%forbidden%'])
                    ->count();
                $coverage['not_indexed'] = (int) \App\Support\Tenancy::table('gsc_coverage_states')
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])
                    ->count();
                $coverage['duplicate'] = (int) \App\Support\Tenancy::table('gsc_coverage_states')
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%duplicate%'])
                    ->count();
            }

            $rankings = [
                'tracked' => 0,
                'top3' => 0,
                'top10' => 0,
                'top20' => 0,
                'below20' => 0,
            ];

            if (Schema::hasTable('seo_rank_snapshots')) {
                $latest = \App\Support\Tenancy::table('seo_rank_snapshots as r1')
                    ->selectRaw('r1.gsc_position as position')
                    ->whereRaw('r1.id = (SELECT MAX(r2.id) FROM seo_rank_snapshots r2 WHERE r2.query = r1.query AND r2.engine = r1.engine AND COALESCE(r2.location, "") = COALESCE(r1.location, "") AND (r2.site_id = ? OR r2.site_id IS NULL))', [\App\Support\Tenancy::currentId()])
                    ->get();

                $rankings['tracked'] = $latest->count();
                $rankings['top3'] = $latest->filter(fn ($r) => $r->position !== null && $r->position <= 3)->count();
                $rankings['top10'] = $latest->filter(fn ($r) => $r->position !== null && $r->position <= 10)->count();
                $rankings['top20'] = $latest->filter(fn ($r) => $r->position !== null && $r->position <= 20)->count();
                $rankings['below20'] = max(0, $rankings['tracked'] - $rankings['top20']);
            }

            // Data-driven action items from the RecommendationEngine (refreshed
            // daily by seo:recommendations-refresh, which also self-heals stale
            // pipelines). Never generated inline — the engine does heavy
            // aggregate queries plus a live HTTP self-probe, which would stall
            // (or on a single-worker server, deadlock) a web request. If the
            // stored output is missing, queue one refresh and show a placeholder.
            $engineOutput = \App\Services\Seo\RecommendationEngine::latest();
            if ($engineOutput === null && \Illuminate\Support\Facades\Cache::add(\App\Support\Tenancy::cacheKey('seo_recs_refresh_queued'), 1, now()->addMinutes(10))) {
                \App\Jobs\RunSeoChannelSyncJob::dispatch('seo:recommendations-refresh', ['--no-heal' => true]);
            }
            $actionItems = $engineOutput['action_items'] ?? ['Generating live recommendations in the background — refresh this page in a minute.'];

            return [
                'channels' => $channels,
                'daily_clicks' => $dailyClicks,
                'coverage' => $coverage,
                'rankings' => $rankings,
                'action_items' => $actionItems,
            ];
        });
    }

    /**
     * @return array<int, array{date:string,gsc:int,bing:int,combined:int}>
     */
    #[Computed]
    public function trendChartData(): array
    {
        $rows = $this->searchSnapshot['daily_clicks'] ?? [];
        $suffix = $this->trendMetric === 'impressions' ? 'impressions' : 'clicks';

        return collect($rows)->map(function (array $row) use ($suffix): array {
            return [
                'date' => (string) ($row['date'] ?? ''),
                'gsc' => (int) ($row['gsc_' . $suffix] ?? 0),
                'bing' => (int) ($row['bing_' . $suffix] ?? 0),
                'combined' => (int) ($row['combined_' . $suffix] ?? 0),
            ];
        })->all();
    }

    /**
     * @return array<int,array{query:string,clicks:int,impressions:int,ctr:float,position:float}>
     */
    #[Computed]
    public function topQueries(): array
    {
        return $this->topRows('query', $this->topQueriesSort, $this->topQueriesDir);
    }

    /**
     * @return array<int,array{page:string,clicks:int,impressions:int,ctr:float,position:float}>
     */
    #[Computed]
    public function topPages(): array
    {
        return $this->topRows('page', $this->topPagesSort, $this->topPagesDir);
    }

    /**
     * Aggregate GSC query metrics grouped by the given dimension over the
     * selected day span, ordered by the chosen sortable column.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function topRows(string $dimension, string $sort, string $direction): array
    {
        if (! Schema::hasTable('gsc_query_metrics')) {
            return [];
        }

        $direction = $direction === 'asc' ? 'asc' : 'desc';

        $orderExpressions = [
            'clicks' => 'SUM(clicks)',
            'impressions' => 'SUM(impressions)',
            'position' => 'AVG(position)',
            'ctr' => 'CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) / SUM(impressions) ELSE 0 END',
            $dimension => $dimension,
        ];

        $orderBy = $orderExpressions[$sort] ?? 'SUM(clicks)';
        $start = Carbon::today()->subDays(max(1, $this->topDays) - 1)->toDateString();
        $end = Carbon::today()->toDateString();

        return \App\Support\Tenancy::table('gsc_query_metrics')
            ->whereBetween('date', [$start, $end])
            ->groupBy($dimension)
            ->selectRaw("{$dimension} as dim, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position")
            ->orderByRaw("{$orderBy} {$direction}")
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                $dimension => (string) $r->dim,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'ctr' => (int) $r->impressions > 0 ? round(((int) $r->clicks / (int) $r->impressions) * 100, 2) : 0.0,
                'position' => round((float) $r->position, 2),
            ])
            ->all();
    }

    /**
     * @return array{score:int,pillars:array<int,array{name:string,score:int,color:string}>}
     */
    #[Computed]
    public function healthSnapshot(): array
    {
        return Cache::remember(\App\Support\Tenancy::cacheKey('admin.seo-reports.health-snapshot'), now()->addMinutes(15), function (): array {
            try {
                Artisan::call('seo:health --json');
                $raw = trim(Artisan::output());
                $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

                $pillars = collect($data['pillars'] ?? [])
                    ->map(function (array $pillar): array {
                        // null score = seo:health could not measure this pillar
                        // for the current tenant. Keep it null: casting to (int)
                        // turns "no data" into a red 0, which reads as a failing
                        // grade for a site that simply has not been measured.
                        $raw = $pillar['score'] ?? null;
                        $score = $raw === null ? null : (int) $raw;

                        return [
                            'name' => (string) ($pillar['name'] ?? 'Unknown'),
                            'score' => $score,
                            'color' => $score === null
                                ? 'zinc'
                                : ($score >= 80 ? 'emerald' : ($score >= 60 ? 'amber' : 'rose')),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'score' => isset($data['score']) ? (int) $data['score'] : null,
                    'pillars' => $pillars,
                ];
            } catch (\Throwable) {
                return [
                    'score' => null,
                    'pillars' => [],
                ];
            }
        });
    }

    public function refreshDashboard(): void
    {
        Cache::forget(\App\Support\Tenancy::cacheKey('admin.seo-reports.health-snapshot'));
        Cache::forget($this->searchSnapshotCacheKey());
        unset($this->files, $this->reportStats, $this->healthSnapshot, $this->searchSnapshot, $this->trendChartData, $this->gscErrorSnapshot);
        $this->flash = 'Dashboard metrics refreshed.';
    }

    /**
     * Microsoft Clarity UX signals: last 7 days vs the prior 7, from the
     * clarity_daily_metrics rows seo:clarity-sync ingests daily. The sync and
     * health check existed for months while the dashboard showed none of it —
     * the only surface was a markdown report nobody opened.
     *
     * @return array{available:bool, latest:?string, week:array<string,int|float>, prior:array<string,int|float>, scroll:?float}
     */
    #[Computed]
    public function claritySnapshot(): array
    {
        if (! Schema::hasTable('clarity_daily_metrics')) {
            return ['available' => false, 'latest' => null, 'week' => [], 'prior' => [], 'scroll' => null];
        }

        return Cache::remember(\App\Support\Tenancy::cacheKey('seo_reports_clarity_v1'), 1800, function (): array {
            $latest = \App\Support\Tenancy::table('clarity_daily_metrics')->max('date');
            if (! $latest) {
                return ['available' => false, 'latest' => null, 'week' => [], 'prior' => [], 'scroll' => null];
            }

            $end = Carbon::parse($latest);
            $sum = fn ($from, $to) => (array) \App\Support\Tenancy::table('clarity_daily_metrics')
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('COALESCE(SUM(sessions),0) sessions, COALESCE(SUM(users),0) users,'
                    . 'COALESCE(SUM(dead_clicks),0) dead_clicks, COALESCE(SUM(rage_clicks),0) rage_clicks,'
                    . 'COALESCE(SUM(quickbacks),0) quickbacks, COALESCE(SUM(script_errors),0) script_errors,'
                    . 'AVG(scroll_depth) scroll_depth')
                ->first();

            $week = $sum((clone $end)->subDays(6), $end);
            $prior = $sum((clone $end)->subDays(13), (clone $end)->subDays(7));

            return [
                'available' => true,
                'latest' => $end->toDateString(),
                'week' => $week,
                'prior' => $prior,
                'scroll' => $week['scroll_depth'] !== null ? round((float) $week['scroll_depth'], 1) : null,
            ];
        });
    }

    /**
     * GEO / AI-crawler readiness: are the machine-readable feeds the AI
     * crawlers read actually being served and regenerated? llms.txt and
     * llms-full.txt are static files rewritten daily at 01:40; the other two
     * are dynamic routes. Age is the tell — a stale llms.txt means the
     * schedule died and every AI crawler is reading last month's site.
     *
     * @return array{feeds:array<int,array{label:string,url:string,ok:bool,age:?string}>}
     */
    #[Computed]
    public function geoSnapshot(): array
    {
        return Cache::remember(\App\Support\Tenancy::cacheKey('seo_reports_geo_v1'), 1800, function (): array {
            $feeds = [];

            foreach ([['llms.txt', 'llms.txt'], ['llms-full.txt', 'llms-full.txt']] as [$label, $file]) {
                $path = public_path($file);
                $mtime = is_file($path) ? filemtime($path) : null;
                $feeds[] = [
                    'label' => $label,
                    'url' => url('/' . $file),
                    'ok' => $mtime !== null,
                    // Stale = older than 2 days (the schedule writes daily).
                    'age' => $mtime ? Carbon::createFromTimestamp($mtime)->diffForHumans() : null,
                    'stale' => $mtime !== null && $mtime < now()->subDays(2)->getTimestamp(),
                ];
            }

            foreach ([['ai-feed.json (dynamic)', '/ai-feed.json'], ['geo/answers.json (dynamic)', '/geo/answers.json']] as [$label, $uri]) {
                $feeds[] = [
                    'label' => $label,
                    'url' => url($uri),
                    'ok' => true,   // dynamic routes; rendered per-request
                    'age' => null,
                    'stale' => false,
                ];
            }

            return ['feeds' => $feeds];
        });
    }

    /**
     * @return array{
     *   available:bool,
     *   totals:array{tracked:int,problem:int,pass:int},
     *   buckets:array<int,array{label:string,count:int}>,
     *   latest_inspected:?string,
     *   rows:array<int,array{url:string,path:string,issue:string,verdict:string,coverage_state:string,page_fetch_state:string,last_crawl_time:?string,inspected_at:?string,last_changed_at:?string,consecutive_failures:int}>
     * }
     */
    #[Computed]
    public function gscErrorSnapshot(): array
    {
        if (! Schema::hasTable('gsc_coverage_states')) {
            return [
                'available' => false,
                'totals' => ['tracked' => 0, 'problem' => 0, 'pass' => 0],
                'buckets' => [],
                'latest_inspected' => null,
                'rows' => [],
            ];
        }

        $tracked = (int) GscCoverageState::query()->count();

        $problemQuery = GscCoverageState::query()
            ->where(function ($q) {
                $q->where('verdict', '!=', 'PASS')
                    ->orWhereNull('verdict')
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%forbidden%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%duplicate%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%soft 404%']);
            });

        $problem = (int) (clone $problemQuery)->count();

        $bucketSpecs = [
            'Blocked (robots/forbidden)' => ['%forbidden%', '%blocked by robots.txt%'],
            'Not indexed' => ['%not indexed%'],
            'Duplicate/canonical' => ['%duplicate%', '%canonical%'],
            'Soft 404' => ['%soft 404%'],
            'Crawl/fetch errors' => ['%not found%', '%server error%', '%redirect error%'],
        ];

        $buckets = [];
        foreach ($bucketSpecs as $label => $patterns) {
            $count = (int) GscCoverageState::query()
                ->where(function ($q) use ($patterns) {
                    foreach ($patterns as $pattern) {
                        $q->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', [$pattern])
                            ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', [$pattern]);
                    }
                })
                ->count();

            $buckets[] = ['label' => $label, 'count' => $count];
        }

        $rows = (clone $problemQuery)
            ->orderByRaw('COALESCE(last_changed_at, inspected_at) DESC')
            ->limit(25)
            ->get([
                'url',
                'verdict',
                'coverage_state',
                'page_fetch_state',
                'last_crawl_time',
                'inspected_at',
                'last_changed_at',
                'consecutive_failures',
            ])
            ->map(function (GscCoverageState $row): array {
                $path = parse_url((string) $row->url, PHP_URL_PATH) ?: '/';
                return [
                    'url' => (string) $row->url,
                    'path' => (string) $path,
                    'issue' => $this->classifyGscIssue((string) $row->coverage_state, (string) $row->page_fetch_state, (string) $row->verdict),
                    'verdict' => (string) ($row->verdict ?? 'UNKNOWN'),
                    'coverage_state' => (string) ($row->coverage_state ?? ''),
                    'page_fetch_state' => (string) ($row->page_fetch_state ?? ''),
                    'last_crawl_time' => $row->last_crawl_time?->toDateString(),
                    'inspected_at' => $row->inspected_at?->diffForHumans(),
                    'last_changed_at' => $row->last_changed_at?->diffForHumans(),
                    'consecutive_failures' => (int) ($row->consecutive_failures ?? 0),
                ];
            })
            ->all();

        $latestInspected = GscCoverageState::query()->max('inspected_at');

        return [
            'available' => true,
            'totals' => [
                'tracked' => $tracked,
                'problem' => $problem,
                'pass' => max(0, $tracked - $problem),
            ],
            'buckets' => $buckets,
            'latest_inspected' => $latestInspected ? Carbon::parse((string) $latestInspected)->diffForHumans() : null,
            'rows' => $rows,
        ];
    }

    protected function classifyGscIssue(string $coverageState, string $pageFetchState, string $verdict): string
    {
        $text = strtolower(trim($coverageState . ' ' . $pageFetchState));

        if ($text === '' && strtoupper($verdict) === 'PASS') {
            return 'Indexed';
        }
        if (str_contains($text, 'forbidden') || str_contains($text, 'robots')) {
            return 'Blocked';
        }
        if (str_contains($text, 'not indexed')) {
            return 'Not indexed';
        }
        if (str_contains($text, 'duplicate') || str_contains($text, 'canonical')) {
            return 'Duplicate/canonical';
        }
        if (str_contains($text, 'soft 404')) {
            return 'Soft 404';
        }
        if (str_contains($text, 'server') || str_contains($text, 'not found') || str_contains($text, 'redirect')) {
            return 'Fetch error';
        }

        return strtoupper($verdict) === 'PASS' ? 'Indexed' : 'Other';
    }

    protected function searchSnapshotCacheKey(): string
    {
        return \App\Support\Tenancy::cacheKey('admin.seo-reports.search-snapshot.' . $this->trendDays);
    }

    protected function percentDelta(int $current, int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    #[Computed]
    public function activeHtml(): ?string
    {
        if ($this->active === null || ! isset($this->reports[$this->active])) {
            return null;
        }
        $path = \App\Support\SeoStorage::path("reports/{$this->active}.md");
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return '<p class="text-zinc-500">Report not yet generated. Click <strong>Run now</strong> to create it.</p>';
        }
        $md = (string) $disk->get($path);
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
        return (string) $converter->convert($md);
    }

    /**
     * Live "why did impressions move" diagnostic: impressions trend, the peak
     * vs. current KPIs, the area pages losing the most impressions, index
     * coverage, and how aggressively the area sprawl is being pruned. Computed
     * from the GSC tables so it stays current instead of a static snapshot.
     *
     * @return array<string,mixed>
     */
    #[Computed]
    public function diagnostic(): array
    {
        return Cache::remember(\App\Support\Tenancy::cacheKey('seo_reports_diagnostic'), now()->addMinutes(15), function (): array {
            $maxDate = \App\Models\GscDailyTotal::max('date');
            if (! $maxDate) {
                return ['available' => false];
            }

            $end = Carbon::parse($maxDate)->startOfDay();
            $start = (clone $end)->subDays(27);

            $rows = \App\Models\GscDailyTotal::whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->orderBy('date')
                ->get(['date', 'impressions', 'clicks', 'position']);

            $recentCut = (clone $end)->subDays(6);          // last 7 days
            $priorLo = (clone $end)->subDays(13);
            $priorHi = (clone $end)->subDays(7);            // the 7 days before that

            $recent = $rows->filter(fn ($r) => Carbon::parse($r->date)->gte($recentCut));
            $prior = $rows->filter(fn ($r) => Carbon::parse($r->date)->betweenIncluded($priorLo, $priorHi));
            $avg = fn ($c, $f) => $c->count() ? (int) round($c->avg($f)) : 0;
            $peakRow = $rows->sortByDesc('impressions')->first();

            // Non-brand CTR across the window (brand terms flatter the average).
            $nb = \App\Support\Tenancy::table('gsc_query_metrics')
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->where('query', 'not like', '%gs construction%')
                ->where('query', 'not like', '%gs builder%')
                ->selectRaw('SUM(impressions) imp, SUM(clicks) clk')
                ->first();
            $nbCtr = ($nb && $nb->imp > 0) ? round(($nb->clk / $nb->imp) * 100, 3) : 0.0;

            // Area pages losing the most impressions, recent 7d vs prior 7d.
            $aggPage = fn ($from, $to) => \App\Support\Tenancy::table('gsc_query_metrics')
                ->whereBetween('date', [$from, $to])
                ->where('page', 'like', '%/areas-served/%')
                ->selectRaw('page, SUM(impressions) imp')
                ->groupBy('page')->pluck('imp', 'page');
            $pa = $aggPage($priorLo->toDateString(), $priorHi->toDateString());
            $pb = $aggPage($recentCut->toDateString(), $end->toDateString());
            $losers = collect($pa->keys())->merge($pb->keys())->unique()
                ->map(function ($p) use ($pa, $pb) {
                    $prior = (int) ($pa[$p] ?? 0);
                    $now = (int) ($pb[$p] ?? 0);

                    return [
                        'path' => parse_url($p, PHP_URL_PATH) ?: $p,
                        'prior' => $prior,
                        'recent' => $now,
                        'drop' => $prior - $now,
                    ];
                })
                ->filter(fn ($r) => $r['drop'] > 0)
                ->sortByDesc('drop')->take(6)->values()->all();

            // Index coverage buckets.
            $cov = GscCoverageState::selectRaw('coverage_state, COUNT(*) c')
                ->groupBy('coverage_state')->pluck('c', 'coverage_state');
            $bucket = fn ($needle) => (int) collect($cov)->filter(
                fn ($c, $state) => str_contains(mb_strtolower((string) $state), $needle)
            )->sum();
            $coverage = [
                'indexed' => $bucket('indexed') - $bucket('not indexed'),
                'not_indexed' => $bucket('not indexed'),
                'not_found' => $bucket('not found'),
                'discovered' => $bucket('discovered') + $bucket('unknown'),
                'total' => (int) $cov->sum(),
            ];

            // How hard the area sprawl is being pruned (mirrors the sitemap).
            $areas = \App\Models\AreaServed::all();
            $areaPageTypes = ['home', 'contact', 'testimonials', 'projects', 'about', 'services'];
            $areaServices = ['kitchen-remodeling', 'bathroom-remodeling', 'home-remodeling', 'basement-remodeling', 'home-additions'];
            $indexable = 0;
            $total = 0;
            foreach ($areas as $area) {
                foreach ($areaPageTypes as $pg) {
                    $total++;
                    if (\App\Support\SEO\AreaSeoPolicy::shouldIndex($area, $pg)) {
                        $indexable++;
                    }
                }
                foreach ($areaServices as $svc) {
                    $total++;
                    if (\App\Support\SEO\AreaSeoPolicy::shouldIndex($area, 'service', $svc)) {
                        $indexable++;
                    }
                }
            }

            return [
                'available' => true,
                'window' => $start->format('M j') . ' – ' . $end->format('M j, Y'),
                'kpis' => [
                    'peak_impr' => (int) ($peakRow->impressions ?? 0),
                    'peak_date' => $peakRow ? Carbon::parse($peakRow->date)->format('M j') : null,
                    'current_impr' => $avg($recent, 'impressions'),
                    'peak_pos' => $prior->count() ? round($prior->avg('position'), 1) : null,
                    'current_pos' => $recent->count() ? round($recent->avg('position'), 1) : null,
                    'nonbrand_ctr' => $nbCtr,
                    'nonbrand_clicks' => (int) ($nb->clk ?? 0),
                    'nonbrand_impr' => (int) ($nb->imp ?? 0),
                ],
                'losers' => $losers,
                'coverage' => $coverage,
                'pruning' => [
                    'indexable' => $indexable,
                    'total' => $total,
                    'priority_cities' => count(\App\Support\SEO\AreaSeoPolicy::priorityCities()),
                    'total_cities' => $areas->count(),
                ],
                // Live, self-expiring recommendations from the engine — each one
                // disappears on its own once its underlying condition heals.
                // Read-only here; generation happens in the scheduled command.
                'recommendations' => (\App\Services\Seo\RecommendationEngine::latest()['recommendations'] ?? null)
                    ?: [['t' => 'Generating recommendations…', 'd' => 'The engine runs daily at 11:10 CT (and was just queued if its output was missing). Refresh in a minute.', 'p' => 'next']],
            ];
        });
    }

    public function render()
    {
        return view('livewire.admin.seo-reports');
    }
}
