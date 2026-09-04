<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Jobs\RunSeoChannelSyncJob;
use App\Models\AreaServed;
use App\Models\GscCoverageState;
use App\Models\GscDailyTotal;
use App\Services\Seo\RecommendationEngine;
use App\Support\SEO\AreaSeoPolicy;
use App\Support\SeoStorage;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Ported from the Livewire admin's SeoReports page (app/Livewire/Admin/SeoReports.php).
 * Every computed property there becomes a slice of one of two endpoints:
 *
 *   - reports list / single report / regenerate  → the generated markdown
 *     reports registered in config('seo-reports.reports').
 *   - snapshot / snapshot refresh                → everything else the page
 *     renders (search performance, health, clarity, GEO, AI traffic, the
 *     embedded GSC-errors summary, and the "why did impressions move"
 *     diagnostic).
 *
 * regenerate() runs the report's artisan command SYNCHRONOUSLY, same as the
 * Livewire original — some of those commands call external APIs (Brave
 * Search, etc.). Never exercised against the live app in verification; see
 * the phpunit coverage instead.
 */
class SeoReportController extends Controller
{
    use BuildsApiResponses;

    protected function reports(): array
    {
        return (array) config('seo-reports.reports', []);
    }

    public function index(): JsonResponse
    {
        $files = $this->files();

        return $this->itemResponse([
            'reports' => $files,
            'stats' => $this->reportStats($files),
        ]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $reports = $this->reports();
        if (! isset($reports[$report])) {
            abort(404, "Unknown report \"{$report}\".");
        }

        return $this->itemResponse($this->reportPayload($report, $reports[$report]));
    }

    public function regenerate(Request $request, string $report): JsonResponse
    {
        $reports = $this->reports();
        if (! isset($reports[$report])) {
            abort(404, "Unknown report \"{$report}\".");
        }

        try {
            Artisan::call($reports[$report]['command']);
            $message = $reports[$report]['label'].' regenerated.';
        } catch (\Throwable $e) {
            $message = 'Failed to regenerate: '.$e->getMessage();
        }

        // Same cache-busting as the Livewire original's regenerate(): health
        // snapshot plus the search snapshot for whichever trend window the
        // caller is currently looking at (defaults match the page default).
        $trendDays = (int) $request->integer('trend_days', 14);
        Cache::forget(Tenancy::cacheKey('admin.seo-reports.health-snapshot'));
        Cache::forget($this->searchSnapshotCacheKey($trendDays));

        $payload = $this->reportPayload($report, $reports[$report]);
        $payload['message'] = $message;

        return $this->itemResponse($payload);
    }

    public function snapshot(Request $request): JsonResponse
    {
        $trendDays = $this->normalizeTrendDays((int) $request->integer('trend_days', 14));
        $trendMetric = $this->normalizeTrendMetric($request->string('trend_metric', 'clicks')->toString());
        $topDays = $this->normalizeTopDays((int) $request->integer('top_days', 28));
        $topQueriesSort = $request->string('top_queries_sort', 'clicks')->toString();
        $topQueriesDir = $request->string('top_queries_dir', 'desc')->toString() === 'asc' ? 'asc' : 'desc';
        $topPagesSort = $request->string('top_pages_sort', 'clicks')->toString();
        $topPagesDir = $request->string('top_pages_dir', 'desc')->toString() === 'asc' ? 'asc' : 'desc';

        $search = $this->searchSnapshot($trendDays);

        return $this->itemResponse([
            'health' => $this->healthSnapshot(),
            'report_stats' => $this->reportStats($this->files()),
            'diagnostic' => $this->diagnostic(),
            'search' => $search,
            'trend' => $this->trendChartData($search, $trendMetric),
            'top_queries' => $this->topRows('query', $topQueriesSort, $topQueriesDir, $topDays),
            'top_pages' => $this->topRows('page', $topPagesSort, $topPagesDir, $topDays),
            'clarity' => $this->claritySnapshot($this->normalizeWindowDays((int) $request->integer('clarity_days', 7))),
            'rankings' => $this->rankingSnapshot($this->normalizeWindowDays((int) $request->integer('rank_days', 7))),
            'geo' => $this->geoSnapshot(),
            'local_falcon' => $this->localFalconSnapshot(),
            'ai_traffic' => $this->aiTrafficSnapshot(),
            'gsc_errors' => $this->gscErrorSnapshot(),
        ]);
    }

    public function refreshSnapshot(Request $request): JsonResponse
    {
        $trendDays = $this->normalizeTrendDays((int) $request->integer('trend_days', 14));

        Cache::forget(Tenancy::cacheKey('admin.seo-reports.health-snapshot'));
        Cache::forget($this->searchSnapshotCacheKey($trendDays));

        $response = $this->snapshot($request);
        $data = $response->getData(true)['data'];
        $data['message'] = 'Dashboard metrics refreshed.';

        return $this->itemResponse($data);
    }

    // -- Reports -----------------------------------------------------------

    protected function reportPayload(string $key, array $meta): array
    {
        $file = $this->fileEntry($key, $meta);
        $file['html'] = $this->reportHtml($key);

        return $file;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function files(): array
    {
        return collect($this->reports())
            ->map(fn (array $meta, string $key) => $this->fileEntry($key, $meta))
            ->values()
            ->all();
    }

    protected function fileEntry(string $key, array $meta): array
    {
        $disk = Storage::disk('local');
        $path = SeoStorage::path("reports/{$key}.md");
        $exists = $disk->exists($path);
        $size = $exists ? $disk->size($path) : null;
        $mtimeTs = $exists ? $disk->lastModified($path) : null;
        $mtime = $mtimeTs ? Carbon::createFromTimestamp($mtimeTs) : null;
        $ageHours = $mtime ? (int) abs(now()->diffInHours($mtime)) : null;
        $freshnessPct = $ageHours === null ? 0 : max(0, 100 - (int) round(min($ageHours, 72) / 72 * 100));
        $status = $ageHours === null ? 'missing' : ($ageHours <= 24 ? 'fresh' : 'stale');

        return [
            'key' => $key,
            'label' => $meta['label'],
            'description' => $meta['description'],
            'command' => $meta['command'],
            'exists' => $exists,
            'size' => $size,
            'mtime' => $mtime?->toIso8601String(),
            'age' => $mtime?->diffForHumans(),
            'age_hours' => $ageHours,
            'freshness_pct' => $freshnessPct,
            'status' => $status,
        ];
    }

    protected function reportStats(array $files): array
    {
        $files = collect($files);
        $generated = $files->where('exists', true);

        return [
            'total' => $files->count(),
            'generated' => $generated->count(),
            'fresh' => $files->where('status', 'fresh')->count(),
            'stale' => $files->where('status', 'stale')->count(),
            'missing' => $files->where('status', 'missing')->count(),
            'updated_today' => $generated->filter(fn (array $f) => ($f['age_hours'] ?? 9999) < 24)->count(),
            'last_update' => $generated
                ->sortByDesc(fn (array $f) => $f['mtime'] ? Carbon::parse($f['mtime'])->timestamp : 0)
                ->first()['age'] ?? null,
        ];
    }

    protected function reportHtml(string $key): string
    {
        $path = SeoStorage::path("reports/{$key}.md");
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

    // -- Search snapshot -----------------------------------------------------

    protected function normalizeTrendDays(int $days): int
    {
        return in_array($days, [7, 14, 30], true) ? $days : 14;
    }

    protected function normalizeTrendMetric(string $metric): string
    {
        return in_array($metric, ['clicks', 'impressions'], true) ? $metric : 'clicks';
    }

    protected function normalizeTopDays(int $days): int
    {
        return in_array($days, [7, 28, 90], true) ? $days : 28;
    }

    protected function searchSnapshot(int $trendDays): array
    {
        return Cache::remember($this->searchSnapshotCacheKey($trendDays), now()->addMinutes(15), function () use ($trendDays): array {
            $today = Carbon::today();
            $currStart = $today->copy()->subDays(6);
            $prevStart = $today->copy()->subDays(13);
            $prevEnd = $today->copy()->subDays(7);

            $channels = [
                'gsc' => ['label' => 'Google Search Console', 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0, 'delta_clicks' => 0.0],
                'bing' => ['label' => 'Bing Webmaster', 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0, 'delta_clicks' => 0.0],
                'gbp' => ['label' => 'Google Business Profile', 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0, 'delta_clicks' => 0.0],
            ];

            if (Schema::hasTable('gsc_query_metrics')) {
                $hasDailyTotals = Schema::hasTable('gsc_daily_totals')
                    && Tenancy::table('gsc_daily_totals')->whereBetween('date', [$prevStart->toDateString(), $today->toDateString()])->exists();
                $totalsTable = $hasDailyTotals ? 'gsc_daily_totals' : 'gsc_query_metrics';

                $curr = Tenancy::table($totalsTable)
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
                    ->first();
                $prev = Tenancy::table($totalsTable)
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
                $positionRow = Tenancy::table('bing_traffic_stats')
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->selectRaw('AVG(position) as position')
                    ->first();

                $hasBingDailyTotals = Schema::hasTable('bing_daily_totals')
                    && Tenancy::table('bing_daily_totals')->whereBetween('date', [$prevStart->toDateString(), $today->toDateString()])->exists();
                $bingTotalsTable = $hasBingDailyTotals ? 'bing_daily_totals' : 'bing_traffic_stats';

                $curr = Tenancy::table($bingTotalsTable)
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions')
                    ->first();
                $prev = Tenancy::table($bingTotalsTable)
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
                $interactionMetrics = ['WEBSITE_CLICKS', 'CALL_CLICKS', 'BUSINESS_DIRECTION_REQUESTS', 'BUSINESS_CONVERSATIONS', 'BUSINESS_BOOKINGS'];
                $impressionMetrics = ['BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH'];

                $currClicks = (int) Tenancy::table('gbp_daily_metrics')
                    ->whereIn('metric', $interactionMetrics)
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->sum('value');
                $prevClicks = (int) Tenancy::table('gbp_daily_metrics')
                    ->whereIn('metric', $interactionMetrics)
                    ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
                    ->sum('value');
                $currImpressions = (int) Tenancy::table('gbp_daily_metrics')
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
            for ($i = $trendDays - 1; $i >= 0; $i--) {
                $day = $today->copy()->subDays($i)->toDateString();

                $dailyTotal = $hasDailyTotalsTable
                    ? Tenancy::table('gsc_daily_totals')->whereDate('date', $day)->first()
                    : null;

                if ($dailyTotal) {
                    $gscDayClicks = (int) $dailyTotal->clicks;
                    $gscDayImpressions = (int) $dailyTotal->impressions;
                } else {
                    $gscDayClicks = Schema::hasTable('gsc_query_metrics')
                        ? (int) Tenancy::table('gsc_query_metrics')->whereDate('date', $day)->sum('clicks')
                        : 0;
                    $gscDayImpressions = Schema::hasTable('gsc_query_metrics')
                        ? (int) Tenancy::table('gsc_query_metrics')->whereDate('date', $day)->sum('impressions')
                        : 0;
                }

                $bingDailyTotal = $hasBingDailyTotalsTable
                    ? Tenancy::table('bing_daily_totals')->whereDate('date', $day)->first()
                    : null;

                if ($bingDailyTotal) {
                    $bingDayClicks = (int) $bingDailyTotal->clicks;
                    $bingDayImpressions = (int) $bingDailyTotal->impressions;
                } else {
                    $bingDayClicks = Schema::hasTable('bing_traffic_stats')
                        ? (int) Tenancy::table('bing_traffic_stats')->whereDate('date', $day)->sum('clicks')
                        : 0;
                    $bingDayImpressions = Schema::hasTable('bing_traffic_stats')
                        ? (int) Tenancy::table('bing_traffic_stats')->whereDate('date', $day)->sum('impressions')
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

            $coverage = ['total' => 0, 'problem' => 0, 'forbidden' => 0, 'not_indexed' => 0, 'duplicate' => 0];
            if (Schema::hasTable('gsc_coverage_states')) {
                $coverage['total'] = (int) Tenancy::table('gsc_coverage_states')->count();
                $coverage['problem'] = (int) Tenancy::table('gsc_coverage_states')
                    ->where(fn ($q) => $q->where('verdict', '!=', 'PASS')->orWhereNull('verdict'))
                    ->count();
                $coverage['forbidden'] = (int) Tenancy::table('gsc_coverage_states')
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%forbidden%'])
                    ->count();
                $coverage['not_indexed'] = (int) Tenancy::table('gsc_coverage_states')
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])
                    ->count();
                $coverage['duplicate'] = (int) Tenancy::table('gsc_coverage_states')
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%duplicate%'])
                    ->count();
            }

            $rankings = ['tracked' => 0, 'top3' => 0, 'top10' => 0, 'top20' => 0, 'below20' => 0];
            if (Schema::hasTable('seo_rank_snapshots')) {
                $latest = Tenancy::table('seo_rank_snapshots as r1')
                    ->selectRaw('r1.gsc_position as position')
                    ->whereRaw('r1.id = (SELECT MAX(r2.id) FROM seo_rank_snapshots r2 WHERE r2.query = r1.query AND r2.engine = r1.engine AND COALESCE(r2.location, "") = COALESCE(r1.location, "") AND (r2.site_id = ? OR r2.site_id IS NULL))', [Tenancy::currentId()])
                    ->get();

                $rankings['tracked'] = $latest->count();
                $rankings['top3'] = $latest->filter(fn ($r) => $r->position !== null && $r->position <= 3)->count();
                $rankings['top10'] = $latest->filter(fn ($r) => $r->position !== null && $r->position <= 10)->count();
                $rankings['top20'] = $latest->filter(fn ($r) => $r->position !== null && $r->position <= 20)->count();
                $rankings['below20'] = max(0, $rankings['tracked'] - $rankings['top20']);
            }

            $engineOutput = RecommendationEngine::latest();
            if ($engineOutput === null && Cache::add(Tenancy::cacheKey('seo_recs_refresh_queued'), 1, now()->addMinutes(10))) {
                RunSeoChannelSyncJob::dispatch('seo:recommendations-refresh', ['--no-heal' => true]);
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

    protected function trendChartData(array $snapshot, string $trendMetric): array
    {
        $rows = $snapshot['daily_clicks'] ?? [];
        $suffix = $trendMetric === 'impressions' ? 'impressions' : 'clicks';

        return collect($rows)->map(fn (array $row): array => [
            'date' => (string) ($row['date'] ?? ''),
            'gsc' => (int) ($row['gsc_'.$suffix] ?? 0),
            'bing' => (int) ($row['bing_'.$suffix] ?? 0),
            'combined' => (int) ($row['combined_'.$suffix] ?? 0),
        ])->all();
    }

    protected function topRows(string $dimension, string $sort, string $direction, int $topDays): array
    {
        if (! Schema::hasTable('gsc_query_metrics')) {
            return [];
        }

        $sortableColumns = ['clicks', 'impressions', 'ctr', 'position'];
        if (! in_array($sort, [$dimension, ...$sortableColumns], true)) {
            $sort = 'clicks';
        }

        $orderExpressions = [
            'clicks' => 'SUM(clicks)',
            'impressions' => 'SUM(impressions)',
            'position' => 'AVG(position)',
            'ctr' => 'CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) / SUM(impressions) ELSE 0 END',
            $dimension => $dimension,
        ];

        $orderBy = $orderExpressions[$sort] ?? 'SUM(clicks)';
        $start = Carbon::today()->subDays(max(1, $topDays) - 1)->toDateString();
        $end = Carbon::today()->toDateString();

        return Tenancy::table('gsc_query_metrics')
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

    protected function healthSnapshot(): array
    {
        return Cache::remember(Tenancy::cacheKey('admin.seo-reports.health-snapshot'), now()->addMinutes(15), function (): array {
            try {
                Artisan::call('seo:health --json');
                $raw = trim(Artisan::output());
                $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

                $pillars = collect($data['pillars'] ?? [])
                    ->map(function (array $pillar): array {
                        $raw = $pillar['score'] ?? null;
                        $score = $raw === null ? null : (int) $raw;

                        return [
                            'name' => (string) ($pillar['name'] ?? 'Unknown'),
                            'score' => $score,
                            'color' => $score === null ? 'zinc' : ($score >= 80 ? 'emerald' : ($score >= 60 ? 'amber' : 'rose')),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'score' => isset($data['score']) ? (int) $data['score'] : null,
                    'pillars' => $pillars,
                ];
            } catch (\Throwable) {
                return ['score' => null, 'pillars' => []];
            }
        });
    }

    /** Allowed comparison windows for the Clarity and Ranking cards. */
    protected function normalizeWindowDays(int $days): int
    {
        return in_array($days, [7, 14, 28], true) ? $days : 7;
    }

    protected function claritySnapshot(int $days = 7): array
    {
        if (! Schema::hasTable('clarity_daily_metrics')) {
            return ['available' => false, 'latest' => null, 'week' => [], 'prior' => [], 'scroll' => null, 'days' => $days];
        }

        return Cache::remember(Tenancy::cacheKey('seo_reports_clarity_v2_' . $days), 1800, function () use ($days): array {
            $latest = Tenancy::table('clarity_daily_metrics')->max('date');
            if (! $latest) {
                return ['available' => false, 'latest' => null, 'week' => [], 'prior' => [], 'scroll' => null, 'days' => $days];
            }

            $end = Carbon::parse($latest);
            $sum = fn ($from, $to) => (array) Tenancy::table('clarity_daily_metrics')
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('COALESCE(SUM(sessions),0) sessions, COALESCE(SUM(users),0) users,'
                    .'COALESCE(SUM(dead_clicks),0) dead_clicks, COALESCE(SUM(rage_clicks),0) rage_clicks,'
                    .'COALESCE(SUM(quickbacks),0) quickbacks, COALESCE(SUM(script_errors),0) script_errors,'
                    .'AVG(scroll_depth) scroll_depth')
                ->first();

            $week = $sum((clone $end)->subDays($days - 1), $end);
            $prior = $sum((clone $end)->subDays(2 * $days - 1), (clone $end)->subDays($days));

            return [
                'available' => true,
                'latest' => $end->toDateString(),
                'week' => $week,
                'prior' => $prior,
                'scroll' => $week['scroll_depth'] !== null ? round((float) $week['scroll_depth'], 1) : null,
                'days' => $days,
            ];
        });
    }

    /**
     * Ranking distribution now vs `daysBack` ago, from the weekly
     * seo:track-rankings snapshots. The card looked frozen because it never
     * said WHEN its numbers were from — the tracker runs weekly (Sundays), so
     * mid-week the data is legitimately up to 6 days old. as_of makes that
     * visible; prior powers the better/worse chevrons.
     */
    protected function rankingSnapshot(int $daysBack = 7): array
    {
        if (! Schema::hasTable('seo_rank_snapshots')) {
            return ['available' => false];
        }

        return Cache::remember(Tenancy::cacheKey('seo_reports_rankdist_v1_' . $daysBack), 1800, function () use ($daysBack): array {
            $distribution = function (?string $before) {
                $q = Tenancy::table('seo_rank_snapshots as r1')
                    ->selectRaw('r1.gsc_position as position')
                    ->whereRaw(
                        'r1.id = (SELECT MAX(r2.id) FROM seo_rank_snapshots r2 WHERE r2.query = r1.query AND r2.engine = r1.engine'
                        . ' AND COALESCE(r2.location, "") = COALESCE(r1.location, "")'
                        . ' AND (r2.site_id = ? OR r2.site_id IS NULL)'
                        . ($before ? ' AND r2.created_at <= ?' : '') . ')',
                        $before ? [Tenancy::currentId(), $before] : [Tenancy::currentId()],
                    );
                if ($before) {
                    $q->where('r1.created_at', '<=', $before);
                }
                $rows = $q->get();

                $b = fn ($max) => $rows->filter(fn ($r) => $r->position !== null && $r->position <= $max)->count();

                return [
                    'tracked' => $rows->count(),
                    'top3' => $b(3),
                    'top10' => $b(10),
                    'top20' => $b(20),
                    'below20' => max(0, $rows->count() - $b(20)),
                ];
            };

            $asOf = Tenancy::table('seo_rank_snapshots')->max('created_at');
            $current = $distribution(null);
            $prior = $distribution($asOf ? Carbon::parse($asOf)->subDays($daysBack)->toDateTimeString() : null);

            return [
                'available' => $current['tracked'] > 0,
                'as_of' => $asOf ? Carbon::parse($asOf)->toDateString() : null,
                'days' => $daysBack,
                'current' => $current,
                'prior' => $prior,
            ];
        });
    }

    protected function geoSnapshot(): array
    {
        return Cache::remember(Tenancy::cacheKey('seo_reports_geo_v1'), 1800, function (): array {
            $feeds = [];
            foreach ([['llms.txt', 'llms.txt'], ['llms-full.txt', 'llms-full.txt']] as [$label, $file]) {
                $path = public_path($file);
                $mtime = is_file($path) ? filemtime($path) : null;
                $feeds[] = [
                    'label' => $label,
                    'url' => url('/'.$file),
                    'ok' => $mtime !== null,
                    'age' => $mtime ? Carbon::createFromTimestamp($mtime)->diffForHumans() : null,
                    'stale' => $mtime !== null && $mtime < now()->subDays(2)->getTimestamp(),
                ];
            }
            foreach ([['ai-feed.json (dynamic)', '/ai-feed.json'], ['geo/answers.json (dynamic)', '/geo/answers.json']] as [$label, $uri]) {
                $feeds[] = ['label' => $label, 'url' => url($uri), 'ok' => true, 'age' => null, 'stale' => false];
            }

            return ['feeds' => $feeds];
        });
    }

    /**
     * Local Falcon map-pack visibility: the latest geo-grid scan per keyword
     * (mirrored daily by localfalcon:sync), its movement since the previous
     * scan, who owns the pack, and which of our towns the business actually
     * appears in — each found grid point snapped to the nearest area we serve.
     */
    protected function localFalconSnapshot(): array
    {
        if (! Schema::hasTable('local_falcon_scans')) {
            return ['available' => false, 'keywords' => []];
        }

        return Cache::remember(Tenancy::cacheKey('seo_reports_local_falcon_v1'), 900, function (): array {
            $scans = Tenancy::table('local_falcon_scans')->whereNotNull('scanned_at')->orderByDesc('scanned_at')->limit(60)->get();
            if ($scans->isEmpty()) {
                return ['available' => false, 'keywords' => []];
            }

            $areas = \App\Models\AreaServed::query()->whereNotNull('latitude')->whereNotNull('longitude')->get(['city', 'slug', 'latitude', 'longitude']);
            $nearestTown = function (float $lat, float $lng) use ($areas): ?array {
                $best = null;
                $bestD = 4.0; // miles — a grid point farther than this from any town we serve stays unnamed
                foreach ($areas as $a) {
                    $d = 69.0 * sqrt((($a->latitude - $lat) ** 2) + (((($a->longitude - $lng) * cos(deg2rad($lat)))) ** 2));
                    if ($d < $bestD) {
                        $bestD = $d;
                        $best = ['city' => $a->city, 'slug' => $a->slug];
                    }
                }

                return $best;
            };

            $ourReviews = Schema::hasTable('review_urls')
                ? (int) \App\Models\Testimonial::query()->where('is_hidden', false)->whereHas('reviewUrls', fn ($q) => $q->where('platform', 'google'))->count()
                : 0;
            $competitorRows = Schema::hasTable('local_falcon_competitors')
                ? Tenancy::table('local_falcon_competitors')->where('pack_points', '>', 0)->get()
                : collect();

            $aiPlatforms = ['chatgpt', 'gemini', 'aimode', 'gaio', 'grok', 'apple'];
            $ai = [];
            foreach ($scans->whereIn('platform', $aiPlatforms)->groupBy(fn ($s) => $s->platform . '|' . $s->keyword) as $g) {
                $s = $g->first();
                $ai[] = ['platform' => $s->platform, 'keyword' => $s->keyword, 'scanned_at' => Carbon::parse($s->scanned_at)->toDateString(), 'saiv' => $s->saiv !== null ? round((float) $s->saiv, 1) : null, 'arp' => $s->arp !== null ? round((float) $s->arp, 1) : null];
            }
            $scans = $scans->reject(fn ($s) => in_array($s->platform, $aiPlatforms, true));

            $keywords = [];
            foreach ($scans->groupBy('keyword') as $keyword => $group) {
                $latest = $group->first();
                $prev = $group->skip(1)->first();
                $raw = json_decode((string) $latest->raw, true) ?: [];
                $detail = $raw['detail'] ?? [];
                $grid = (array) ($detail['grid'] ?? []);
                $points = count($grid) ?: (int) ($detail['points_total'] ?? 0);
                if ($points === 0) {
                    continue; // pre-campaign scans mirrored without per-point data (and the misspelled keyword)
                }
                $found = array_values(array_filter($grid, fn ($pt) => ($pt['rank'] ?? false) !== false));

                $towns = [];
                foreach ($found as $pt) {
                    if (! is_numeric($pt['lat'] ?? null) || ! is_numeric($pt['lng'] ?? null)) {
                        continue;
                    }
                    $t = $nearestTown((float) $pt['lat'], (float) $pt['lng']);
                    $name = $t['city'] ?? 'unnamed point';
                    $rank = (int) $pt['rank'];
                    if (! isset($towns[$name]) || $rank < $towns[$name]['best_rank']) {
                        $towns[$name] = ['city' => $name, 'slug' => $t['slug'] ?? null, 'best_rank' => $rank, 'points' => ($towns[$name]['points'] ?? 0) + 1];
                    } else {
                        $towns[$name]['points']++;
                    }
                }
                usort($towns, fn ($a, $b) => $a['best_rank'] <=> $b['best_rank']);

                $keywords[] = [
                    'keyword' => $keyword,
                    'scanned_at' => Carbon::parse($latest->scanned_at)->toDateString(),
                    'grid' => $latest->grid_points ? "{$latest->grid_points}×{$latest->grid_points}" : null,
                    'radius' => $detail['radius'] ?? null,
                    'arp' => $latest->arp !== null ? round((float) $latest->arp, 1) : null,
                    'atrp' => $latest->atrp !== null ? round((float) $latest->atrp, 1) : null,
                    'solv' => $latest->solv !== null ? round((float) $latest->solv, 1) : null,
                    'found' => count($found) ?: (int) ($detail['found'] ?? 0),
                    'points' => $points,
                    'top3' => count(array_filter($found, fn ($pt) => (int) $pt['rank'] <= 3)),
                    'prev' => $prev ? [
                        'scanned_at' => Carbon::parse($prev->scanned_at)->toDateString(),
                        'arp' => $prev->arp !== null ? round((float) $prev->arp, 1) : null,
                        'solv' => $prev->solv !== null ? round((float) $prev->solv, 1) : null,
                    ] : null,
                    'towns' => array_values($towns),
                    'pack_leaders' => $competitorRows->where('keyword', $keyword)->where('scan_id', $latest->scan_id)->sortByDesc('pack_points')->take(6)->map(fn ($c) => [
                        'business' => $c->name, 'appearances' => (int) $c->pack_points, 'best_rank' => $c->best_rank, 'reviews' => $c->reviews, 'rating' => $c->rating !== null ? (float) $c->rating : null, 'url' => $c->url, 'host' => $c->host,
                        'services' => $c->site_services ? json_decode($c->site_services, true) : null,
                        'towns' => $c->site_towns ? json_decode($c->site_towns, true) : null,
                        'site_read' => $c->site_fetched_at !== null && $c->site_title !== null,
                    ])->values()->all() ?: array_slice((array) ($detail['pack_leaders'] ?? []), 0, 5),
                    'review_gap' => (function () use ($competitorRows, $keyword, $latest, $ourReviews) {
                        $top = $competitorRows->where('keyword', $keyword)->where('scan_id', $latest->scan_id)->sortByDesc('pack_points')->take(6)->pluck('reviews')->filter()->max();
                        return $top ? ['ours' => $ourReviews, 'leader' => (int) $top, 'gap' => max(0, (int) $top - $ourReviews)] : null;
                    })(),
                    'report_url' => $detail['public_url'] ?? null,
                    'heatmap' => $detail['heatmap'] ?? null,
                ];
            }

            return ['available' => true, 'keywords' => $keywords, 'ai' => $ai, 'our_reviews' => $ourReviews, 'latest' => Carbon::parse(($scans->first() ?? collect($ai)->first())?->scanned_at ?? now())->toDateString()];
        });
    }

    protected function aiTrafficSnapshot(): array
    {
        if (! Schema::hasTable('ai_traffic_daily')) {
            return ['referrals' => [], 'crawlers' => [], 'total_referrals' => 0, 'total_crawls' => 0];
        }

        return Cache::remember(Tenancy::cacheKey('seo_reports_ai_traffic_v1'), 900, function (): array {
            $rows = Tenancy::table('ai_traffic_daily')
                ->where('date', '>=', now()->subDays(28)->toDateString())
                ->select('kind', 'source', DB::raw('SUM(count) total'))
                ->groupBy('kind', 'source')
                ->orderByDesc('total')
                ->get();

            $referrals = [];
            $crawlers = [];
            foreach ($rows as $r) {
                if ($r->kind === 'referral') {
                    $referrals[$r->source] = (int) $r->total;
                } else {
                    $crawlers[$r->source] = (int) $r->total;
                }
            }

            return [
                'referrals' => $referrals,
                'crawlers' => $crawlers,
                'total_referrals' => array_sum($referrals),
                'total_crawls' => array_sum($crawlers),
            ];
        });
    }

    protected function gscErrorSnapshot(): array
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

        $problemQuery = GscCoverageState::query()->where(function ($q) {
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
            ->get(['url', 'verdict', 'coverage_state', 'page_fetch_state', 'last_crawl_time', 'inspected_at', 'last_changed_at', 'consecutive_failures'])
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
            'totals' => ['tracked' => $tracked, 'problem' => $problem, 'pass' => max(0, $tracked - $problem)],
            'buckets' => $buckets,
            'latest_inspected' => $latestInspected ? Carbon::parse((string) $latestInspected)->diffForHumans() : null,
            'rows' => $rows,
        ];
    }

    protected function classifyGscIssue(string $coverageState, string $pageFetchState, string $verdict): string
    {
        $text = strtolower(trim($coverageState.' '.$pageFetchState));

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

    protected function diagnostic(): array
    {
        return Cache::remember(Tenancy::cacheKey('seo_reports_diagnostic'), now()->addMinutes(15), function (): array {
            $maxDate = GscDailyTotal::max('date');
            if (! $maxDate) {
                return ['available' => false];
            }

            $end = Carbon::parse($maxDate)->startOfDay();
            $start = (clone $end)->subDays(27);

            $rows = GscDailyTotal::whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->orderBy('date')
                ->get(['date', 'impressions', 'clicks', 'position']);

            $recentCut = (clone $end)->subDays(6);
            $priorLo = (clone $end)->subDays(13);
            $priorHi = (clone $end)->subDays(7);

            $recent = $rows->filter(fn ($r) => Carbon::parse($r->date)->gte($recentCut));
            $prior = $rows->filter(fn ($r) => Carbon::parse($r->date)->betweenIncluded($priorLo, $priorHi));
            $avg = fn ($c, $f) => $c->count() ? (int) round($c->avg($f)) : 0;
            $peakRow = $rows->sortByDesc('impressions')->first();

            $nbWindow = fn (string $from, string $to) => Tenancy::table('gsc_query_metrics')
                ->whereBetween('date', [$from, $to])
                ->where('query', 'not like', '%gs construction%')
                ->where('query', 'not like', '%gs builder%')
                ->selectRaw('SUM(impressions) imp, SUM(clicks) clk')
                ->first();
            $nb = $nbWindow($start->toDateString(), $end->toDateString());
            // Same-length window immediately before, for the trend chevron.
            $nbPrior = $nbWindow((clone $start)->subDays(28)->toDateString(), (clone $start)->subDay()->toDateString());
            $nbCtr = ($nb && $nb->imp > 0) ? round(($nb->clk / $nb->imp) * 100, 3) : 0.0;
            $nbPriorCtr = ($nbPrior && $nbPrior->imp > 0) ? round(($nbPrior->clk / $nbPrior->imp) * 100, 3) : 0.0;

            $aggPage = fn ($from, $to) => Tenancy::table('gsc_query_metrics')
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

                    return ['path' => parse_url($p, PHP_URL_PATH) ?: $p, 'prior' => $prior, 'recent' => $now, 'drop' => $prior - $now];
                })
                ->filter(fn ($r) => $r['drop'] > 0)
                ->sortByDesc('drop')->take(6)->values()->all();

            $cov = GscCoverageState::selectRaw('coverage_state, COUNT(*) c')->groupBy('coverage_state')->pluck('c', 'coverage_state');
            $bucket = fn ($needle) => (int) collect($cov)->filter(fn ($c, $state) => str_contains(mb_strtolower((string) $state), $needle))->sum();
            $coverage = [
                'indexed' => $bucket('indexed') - $bucket('not indexed'),
                'not_indexed' => $bucket('not indexed'),
                'not_found' => $bucket('not found'),
                'discovered' => $bucket('discovered') + $bucket('unknown'),
                'total' => (int) $cov->sum(),
            ];

            $areas = AreaServed::all();
            $areaPageTypes = ['home', 'contact', 'testimonials', 'projects', 'about', 'services'];
            $areaServices = ['kitchen-remodeling', 'bathroom-remodeling', 'home-remodeling', 'basement-remodeling', 'home-additions'];
            $indexable = 0;
            $total = 0;
            foreach ($areas as $area) {
                foreach ($areaPageTypes as $pg) {
                    $total++;
                    if (AreaSeoPolicy::shouldIndex($area, $pg)) {
                        $indexable++;
                    }
                }
                foreach ($areaServices as $svc) {
                    $total++;
                    if (AreaSeoPolicy::shouldIndex($area, 'service', $svc)) {
                        $indexable++;
                    }
                }
            }

            return [
                'available' => true,
                'window' => $start->format('M j').' – '.$end->format('M j, Y'),
                'kpis' => [
                    'peak_impr' => (int) ($peakRow->impressions ?? 0),
                    'peak_date' => $peakRow ? Carbon::parse($peakRow->date)->format('M j') : null,
                    'current_impr' => $avg($recent, 'impressions'),
                    'peak_pos' => $prior->count() ? round($prior->avg('position'), 1) : null,
                    'current_pos' => $recent->count() ? round($recent->avg('position'), 1) : null,
                    'nonbrand_ctr' => $nbCtr,
                    'nonbrand_clicks' => (int) ($nb->clk ?? 0),
                    'nonbrand_impr' => (int) ($nb->imp ?? 0),
                    'nonbrand_prior_ctr' => $nbPriorCtr,
                    'nonbrand_prior_clicks' => (int) ($nbPrior->clk ?? 0),
                    'nonbrand_prior_impr' => (int) ($nbPrior->imp ?? 0),
                ],
                'losers' => $losers,
                'coverage' => $coverage,
                'pruning' => [
                    'indexable' => $indexable,
                    'total' => $total,
                    'priority_cities' => count(AreaSeoPolicy::priorityCities()),
                    'total_cities' => $areas->count(),
                ],
                'recommendations' => (RecommendationEngine::latest()['recommendations'] ?? null)
                    ?: [['t' => 'Generating recommendations…', 'd' => 'The engine runs daily at 11:10 CT (and was just queued if its output was missing). Refresh in a minute.', 'p' => 'next']],
            ];
        });
    }

    protected function searchSnapshotCacheKey(int $trendDays): string
    {
        return Tenancy::cacheKey('admin.seo-reports.search-snapshot.'.$trendDays);
    }

    protected function percentDelta(int $current, int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
