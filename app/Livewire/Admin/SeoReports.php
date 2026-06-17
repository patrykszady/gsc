<?php

namespace App\Livewire\Admin;

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
     * Registry of reports rendered in the dashboard. Keys are file names
     * (without extension) under storage/app/reports/; each value provides
     * the display label and the artisan command that regenerates it.
     *
     * @var array<string, array{label:string, command:string, description:string}>
     */
    protected array $reports = [
        'content-decay' => [
            'label' => 'Content decay',
            'command' => 'seo:content-decay --markdown',
            'description' => 'Pages losing clicks or position week over week.',
        ],
        'content-gap' => [
            'label' => 'Content gap (rank 8–20)',
            'command' => 'seo:content-gap --markdown',
            'description' => 'Striking-distance queries clustered into content briefs.',
        ],
        'cwv-template' => [
            'label' => 'CWV by template',
            'command' => 'seo:cwv-template --markdown',
            'description' => 'p75 LCP/INP/CLS per page template with regression alerts.',
        ],
        'gbp-parity' => [
            'label' => 'GBP / local-SEO parity',
            'command' => 'seo:gbp-parity --markdown',
            'description' => 'NAP consistency and Google Business Profile ↔ site service parity.',
        ],
        'internal-link-suggest' => [
            'label' => 'Internal-link suggestions',
            'command' => 'seo:internal-link-suggest --markdown',
            'description' => 'Unlinked plain-text mentions of other pages’ anchors.',
        ],
        'backlinks-monitor' => [
            'label' => 'Backlinks / mentions',
            'command' => 'seo:backlinks-monitor --markdown',
            'description' => 'New and lost referring hosts (via SerpApi).',
        ],
        'schema-audit' => [
            'label' => 'Schema audit',
            'command' => 'seo:schema-audit --markdown',
            'description' => 'JSON-LD coverage and validity sweep.',
        ],
        'area-pages-audit' => [
            'label' => 'Area pages (thin/dup)',
            'command' => 'seo:area-pages-audit --markdown',
            'description' => 'Thin pages and near-duplicate clusters across per-area landing pages.',
        ],
        'health-check' => [
            'label' => 'Local SEO health-check',
            'command' => 'seo:health-check --markdown',
            'description' => 'Composite 0–100 score per URL across title, meta, H1, alt, links, schema, canonical, word count.',
        ],
        'clarity-health' => [
            'label' => 'Clarity health',
            'command' => 'seo:clarity-health --markdown',
            'description' => 'Clarity API/config status, last sync freshness, and latest behavioral metrics snapshot.',
        ],
    ];

    public function mount(?string $report = null): void
    {
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
        Cache::forget('admin.seo-reports.health-snapshot');
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
            $path = "reports/{$key}.md";
            $exists = $disk->exists($path);
            $size = $exists ? $disk->size($path) : null;
            $mtimeTs = $exists ? $disk->lastModified($path) : null;
            $mtime = $mtimeTs ? Carbon::createFromTimestamp($mtimeTs) : null;
            $ageHours = $mtime ? (int) now()->diffInHours($mtime) : null;
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
                    && DB::table('gsc_daily_totals')->whereBetween('date', [$prevStart->toDateString(), $today->toDateString()])->exists();

                $totalsTable = $hasDailyTotals ? 'gsc_daily_totals' : 'gsc_query_metrics';

                $curr = DB::table($totalsTable)
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
                    ->first();

                $prev = DB::table($totalsTable)
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
                $positionRow = DB::table('bing_traffic_stats')
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->selectRaw('AVG(position) as position')
                    ->first();

                // Prefer true site-wide daily totals (GetRankAndTrafficStats)
                // which include anonymized traffic the query table drops.
                $hasBingDailyTotals = Schema::hasTable('bing_daily_totals')
                    && DB::table('bing_daily_totals')->whereBetween('date', [$prevStart->toDateString(), $today->toDateString()])->exists();

                $bingTotalsTable = $hasBingDailyTotals ? 'bing_daily_totals' : 'bing_traffic_stats';

                $curr = DB::table($bingTotalsTable)
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions')
                    ->first();

                $prev = DB::table($bingTotalsTable)
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

                $currClicks = (int) DB::table('gbp_daily_metrics')
                    ->whereIn('metric', $interactionMetrics)
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->sum('value');

                $prevClicks = (int) DB::table('gbp_daily_metrics')
                    ->whereIn('metric', $interactionMetrics)
                    ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
                    ->sum('value');

                $currImpressions = (int) DB::table('gbp_daily_metrics')
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
                    ? DB::table('gsc_daily_totals')->whereDate('date', $day)->first()
                    : null;

                if ($dailyTotal) {
                    $gscDayClicks = (int) $dailyTotal->clicks;
                    $gscDayImpressions = (int) $dailyTotal->impressions;
                } else {
                    $gscDayClicks = Schema::hasTable('gsc_query_metrics')
                        ? (int) DB::table('gsc_query_metrics')->whereDate('date', $day)->sum('clicks')
                        : 0;

                    $gscDayImpressions = Schema::hasTable('gsc_query_metrics')
                        ? (int) DB::table('gsc_query_metrics')->whereDate('date', $day)->sum('impressions')
                        : 0;
                }

                // Prefer true Bing daily totals; fall back to query-stat sums.
                $bingDailyTotal = $hasBingDailyTotalsTable
                    ? DB::table('bing_daily_totals')->whereDate('date', $day)->first()
                    : null;

                if ($bingDailyTotal) {
                    $bingDayClicks = (int) $bingDailyTotal->clicks;
                    $bingDayImpressions = (int) $bingDailyTotal->impressions;
                } else {
                    $bingDayClicks = Schema::hasTable('bing_traffic_stats')
                        ? (int) DB::table('bing_traffic_stats')->whereDate('date', $day)->sum('clicks')
                        : 0;

                    $bingDayImpressions = Schema::hasTable('bing_traffic_stats')
                        ? (int) DB::table('bing_traffic_stats')->whereDate('date', $day)->sum('impressions')
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
                $coverage['total'] = (int) DB::table('gsc_coverage_states')->count();
                $coverage['problem'] = (int) DB::table('gsc_coverage_states')
                    ->where(function ($q) {
                        $q->where('verdict', '!=', 'PASS')->orWhereNull('verdict');
                    })
                    ->count();
                $coverage['forbidden'] = (int) DB::table('gsc_coverage_states')
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%forbidden%'])
                    ->count();
                $coverage['not_indexed'] = (int) DB::table('gsc_coverage_states')
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])
                    ->count();
                $coverage['duplicate'] = (int) DB::table('gsc_coverage_states')
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
                $latest = DB::table('seo_rank_snapshots as r1')
                    ->selectRaw('r1.gsc_position as position')
                    ->whereRaw('r1.id = (SELECT MAX(r2.id) FROM seo_rank_snapshots r2 WHERE r2.query = r1.query AND r2.engine = r1.engine AND COALESCE(r2.location, "") = COALESCE(r1.location, ""))')
                    ->get();

                $rankings['tracked'] = $latest->count();
                $rankings['top3'] = $latest->filter(fn ($r) => $r->position !== null && $r->position <= 3)->count();
                $rankings['top10'] = $latest->filter(fn ($r) => $r->position !== null && $r->position <= 10)->count();
                $rankings['top20'] = $latest->filter(fn ($r) => $r->position !== null && $r->position <= 20)->count();
                $rankings['below20'] = max(0, $rankings['tracked'] - $rankings['top20']);
            }

            $actionItems = [];
            if (($coverage['problem'] ?? 0) > 0) {
                $actionItems[] = "{$coverage['problem']} URLs have non-pass coverage verdicts. Run `seo:reindex-problem-pages --auto` and review access/canonical states.";
            }
            if (($rankings['tracked'] ?? 0) > 0 && ($rankings['top10'] / max(1, $rankings['tracked'])) < 0.4) {
                $actionItems[] = 'Less than 40% of tracked terms are in top 10. Prioritize city-service pages with high impressions and average position 8-20.';
            }
            if (($channels['gsc']['delta_clicks'] ?? 0) < -10) {
                $actionItems[] = 'Google clicks are down more than 10% vs prior 7 days. Check top-loss queries and titles/meta for affected pages.';
            }
            if (($channels['bing']['impressions'] ?? 0) < 100) {
                $actionItems[] = 'Bing visibility is low. Confirm `seo:bing-sync` runs daily and ensure sitemap is submitted in Bing Webmaster.';
            }
            if (($channels['gbp']['clicks'] ?? 0) < 20) {
                $actionItems[] = 'GBP engagement is light. Increase GBP post cadence and add fresh geotagged project photos this week.';
            }
            if (empty($actionItems)) {
                $actionItems[] = 'No urgent anomalies detected. Keep daily syncs healthy and continue shipping localized content + backlinks.';
            }

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

        return DB::table('gsc_query_metrics')
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
        return Cache::remember('admin.seo-reports.health-snapshot', now()->addMinutes(15), function (): array {
            try {
                Artisan::call('seo:health --json');
                $raw = trim(Artisan::output());
                $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

                $pillars = collect($data['pillars'] ?? [])
                    ->map(function (array $pillar): array {
                        $score = (int) ($pillar['score'] ?? 0);

                        return [
                            'name' => (string) ($pillar['name'] ?? 'Unknown'),
                            'score' => $score,
                            'color' => $score >= 80 ? 'emerald' : ($score >= 60 ? 'amber' : 'rose'),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'score' => (int) ($data['score'] ?? 0),
                    'pillars' => $pillars,
                ];
            } catch (\Throwable) {
                return [
                    'score' => 0,
                    'pillars' => [],
                ];
            }
        });
    }

    public function refreshDashboard(): void
    {
        Cache::forget('admin.seo-reports.health-snapshot');
        Cache::forget($this->searchSnapshotCacheKey());
        unset($this->files, $this->reportStats, $this->healthSnapshot, $this->searchSnapshot, $this->trendChartData);
        $this->flash = 'Dashboard metrics refreshed.';
    }

    protected function searchSnapshotCacheKey(): string
    {
        return 'admin.seo-reports.search-snapshot.' . $this->trendDays;
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
        $path = "reports/{$this->active}.md";
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

    public function render()
    {
        return view('livewire.admin.seo-reports');
    }
}
