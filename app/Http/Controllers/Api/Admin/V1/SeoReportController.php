<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunSeoChannelSyncJob;
use App\Models\AreaServed;
use App\Models\GscCoverageState;
use App\Models\GscDailyTotal;
use App\Models\Site;
use App\Services\Seo\RecommendationEngine;
use App\Support\SEO\AreaSeoPolicy;
use App\Support\Seo\FrustratedPages;
use App\Support\Seo\Reports\ReportCapabilities;
use App\Support\Seo\Reports\ReportRefresh;
use App\Support\Seo\SearchConsoleProperty;
use App\Support\SeoStorage;
use App\Support\Tenancy;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use SsSystems\Platform\Pulse\SnapshotBuilder;
use SsSystems\Platform\Reports\Console\ReportRun;
use SsSystems\Platform\Reports\Jobs\RunArtisanCommandDetached;
use SsSystems\Platform\Seo\Faq\Bank\FaqBankStore;
use SsSystems\Platform\Seo\Http\Concerns\BuildsSeoToolBlocks;
use SsSystems\Platform\Seo\SearchAppearance;
use SsSystems\Platform\Seo\SitemapStatus;
use Throwable;

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
 * Livewire original — some of those commands call external APIs (PageSpeed
 * Search, etc.). Never exercised against the live app in verification; see
 * the phpunit coverage instead.
 */
class SeoReportController extends Controller
{
    use BuildsApiResponses;
    use BuildsSeoToolBlocks;

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
            'batch' => ReportRefresh::progress(),
        ]);
    }

    /**
     * POST seo/reports/refresh — refresh every report that needs it (or all
     * of them with only_stale=false) in the background and answer at once:
     * the slow reports take longer than a web request may run. Progress
     * comes back as `batch` on GET seo/reports.
     *
     * gsc is multi-tenant, but this API is pinned to the 'gsc' tenant
     * (PinAdminApiTenant) — the detached command is handed `--site` explicitly
     * so it refreshes the SAME tenant whether or not console/queue code's own
     * default-site fallback still happens to agree with that pin.
     */
    public function refresh(Request $request): JsonResponse
    {
        if (ReportRefresh::running()) {
            return $this->itemResponse(['ok' => true, 'queued' => false, 'running' => true, 'keys' => [], 'batch' => ReportRefresh::progress()]);
        }

        $onlyStale = $request->boolean('only_stale', true);
        $keys = ReportRefresh::keysToRun($onlyStale);

        if ($keys === []) {
            return $this->itemResponse(['ok' => true, 'queued' => false, 'running' => false, 'keys' => [], 'message' => 'Every report is up to date.', 'batch' => ReportRefresh::progress()]);
        }

        Log::channel('seo-reports')->info('report library refresh requested', [
            'reports' => $keys,
            'requested_by' => $request->header('X-Admin-User'),
        ]);

        $batch = ReportRefresh::markQueued($keys);
        RunArtisanCommandDetached::dispatch('seo:reports-refresh', [
            '--keys' => implode(',', $keys),
            '--site' => Site::current()->slug,
        ]);

        return $this->itemResponse(['ok' => true, 'queued' => true, 'running' => true, 'keys' => $keys, 'batch' => $batch]);
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

        $availability = ReportCapabilities::availability($report);
        if (! $availability['available']) {
            Log::channel('seo-reports')->info('report run refused', [
                'key' => $report,
                'missing' => $availability['missing'],
                'requested_by' => $request->header('X-Admin-User'),
                'screen' => $request->header('X-Admin-Screen'),
            ]);

            $payload = $this->reportPayload($report, $reports[$report]);
            $payload['ok'] = false;
            $payload['status'] = 'unavailable';
            $payload['message'] = $availability['reason'];
            $payload['run'] = null;

            return $this->itemResponse($payload);
        }

        $trendDays = (int) $request->integer('trend_days', 14);

        // Kit 0.12.0: SsSystems\Platform\Reports\Console\ReportRun replaces
        // this app's own SeoReportRun. The kit class never hears about
        // tenancy — the 'site' context here is folded into every log line
        // exactly as SeoReportRun's own $context did, and the report file's
        // path is scoped by the ReportStorage this app bound in
        // AppServiceProvider (TenantScopedReportStorage), never by the kit.
        $run = ReportRun::run($report, $reports[$report], $request, $trendDays, [
            'site' => Site::current()?->slug,
        ]);

        // Same cache-busting as the Livewire original's regenerate(): health
        // snapshot plus the search snapshot for whichever trend window the
        // caller is currently looking at (defaults match the page default).
        // Busted regardless of the run's outcome, same as before — a failed
        // run can still have left a stale cache from an earlier good run.
        Cache::forget(Tenancy::cacheKey('admin.seo-reports.health-snapshot'));
        Cache::forget($this->searchSnapshotCacheKey($trendDays));

        $payload = $this->reportPayload($report, $reports[$report]);
        $payload['ok'] = $run['ok'];
        $payload['status'] = $run['status'];
        $payload['message'] = $run['message'];
        $payload['run'] = $run;

        return $this->itemResponse($payload);
    }

    public function snapshot(Request $request): JsonResponse
    {
        $trendDays = $this->normalizeTrendDays((int) $request->integer('trend_days', 14));
        $topDays = $this->normalizeTopDays((int) $request->integer('top_days', 28));
        $topQueriesSort = $request->string('top_queries_sort', 'clicks')->toString();
        $topQueriesDir = $request->string('top_queries_dir', 'desc')->toString() === 'asc' ? 'asc' : 'desc';
        $topPagesSort = $request->string('top_pages_sort', 'clicks')->toString();
        $topPagesDir = $request->string('top_pages_dir', 'desc')->toString() === 'asc' ? 'asc' : 'desc';

        $search = $this->searchSnapshot($trendDays);

        // The kit's SEO tools (0.19.2, 2026-11-01): rankings, keyword
        // research, competitor visibility (dataforseo), AI traffic, the
        // map-pack grid AND change intel, all built and cached by the
        // shared kit the same way ss.systems' hub and every other adopter
        // read them — BuildsSeoToolBlocks::seoToolBlockCacheKeys() is what
        // refreshSnapshot() forgets below. 'balance' is written by the
        // (unported) daily seo:dataforseo-balance-check command, straight
        // into Cache, not through any kit store — merged in here same as
        // before.
        $seoToolBlocks = $this->seoToolBlocks(
            $this->normalizeWindowDays((int) $request->integer('rank_days', 7)),
            fn (string $key, int $ttl, callable $build): array => Cache::remember(Tenancy::cacheKey($key), $ttl, $build),
        );
        $seoToolBlocks['dataforseo']['balance'] = Cache::get('seo.dataforseo.balance');

        return $this->itemResponse([
            'health' => $this->healthSnapshot(),
            'report_stats' => $this->reportStats($this->files()),
            'diagnostic' => $this->diagnostic(),
            'search' => $search,
            'trend' => $this->trendChartData($search),
            'trend_through' => $search['through'] ?? null,
            'top_queries' => $this->topRows('query', $topQueriesSort, $topQueriesDir, $topDays),
            'top_pages' => $this->topRows('page', $topPagesSort, $topPagesDir, $topDays),
            'clarity' => $this->claritySnapshot($this->normalizeWindowDays((int) $request->integer('clarity_days', 7))),
            'geo' => $this->geoSnapshot(),
            ...$seoToolBlocks,
            'gsc_errors' => $this->gscErrorSnapshot(),
            // What Google actually knows about our sitemaps. Submitting them
            // nightly told nobody anything: the run logged success and the
            // screen stayed silent, so "is our sitemap on Search Console?"
            // could only be answered from the Search Console UI.
            'sitemaps' => SitemapStatus::snapshot(SearchConsoleProperty::url()),
            // How our results LOOK on Google (star ratings, product details,
            // AI Overviews). Thin non-brand CTR reads as a ranking problem
            // until you can see the impressions are landing in a result
            // shape nobody clicks.
            'search_appearance' => SearchAppearance::snapshot(
                fn () => Tenancy::table(SearchAppearance::TABLE),
                $this->normalizeTopDays((int) $request->integer('appearance_days', 28)),
            ),
            // Site Pulse (SsSystems\Platform\Pulse, kit 0.10.0) — first-party
            // usage telemetry read by ss-systems' Site Pulse card. Cached 5
            // minutes like Dawn's own (shorter than most sections here,
            // which run 15/30 min, per the shared kit's spec).
            'pulse' => $this->pulseSnapshot(),
        ]);
    }

    public function refreshSnapshot(Request $request): JsonResponse
    {
        $trendDays = $this->normalizeTrendDays((int) $request->integer('trend_days', 14));

        $context = [
            'trend_days' => $trendDays,
            'site' => Site::current()?->slug,
            'requested_by' => $request->header('X-Admin-User'),
            'screen' => $request->header('X-Admin-Screen'),
        ];

        Log::channel('seo-reports')->info('snapshot refresh started', $context);

        $startedAt = Carbon::now();
        $start = microtime(true);

        try {
            $healthKey = Tenancy::cacheKey('admin.seo-reports.health-snapshot');
            $searchKey = $this->searchSnapshotCacheKey($trendDays);
            $pulseKey = Tenancy::cacheKey('admin.seo-reports.pulse');
            Cache::forget($healthKey);
            Cache::forget($searchKey);
            Cache::forget($pulseKey);
            SitemapStatus::forget(SearchConsoleProperty::url());
            // The kit's SEO-tool blocks (rankings/keyword_research/
            // dataforseo/ai_traffic/map_pack) cache themselves under
            // tenant-keyed names BuildsSeoToolBlocks::seoToolBlockCacheKeys()
            // enumerates — none of this was forgotten on refresh before the
            // kit port, so a manual Refresh click never actually re-ran them.
            $seoToolKeys = array_map(static fn (string $key): string => Tenancy::cacheKey($key), self::seoToolBlockCacheKeys());
            foreach ($seoToolKeys as $key) {
                Cache::forget($key);
            }
            $cachesCleared = [$healthKey, $searchKey, $pulseKey, 'sitemap-status', ...$seoToolKeys];

            $response = $this->snapshot($request);
            $data = $response->getData(true)['data'];

            $finishedAt = Carbon::now();
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            Log::channel('seo-reports')->info('snapshot refresh finished', $context + [
                'duration_ms' => $durationMs,
                'caches_cleared' => $cachesCleared,
                'top_queries' => count($data['top_queries'] ?? []),
                'top_pages' => count($data['top_pages'] ?? []),
                'trend_points' => count($data['trend'] ?? []),
                'health_score_present' => isset($data['health']['score']),
            ]);

            $seconds = number_format($durationMs / 1000, 1);
            $data['ok'] = true;
            $data['message'] = "Dashboard metrics refreshed in {$seconds} s.";
            $data['run'] = [
                'duration_ms' => $durationMs,
                'caches_cleared' => $cachesCleared,
                'started_at' => $startedAt->toIso8601String(),
                'finished_at' => $finishedAt->toIso8601String(),
                'log_channel' => 'seo-reports',
            ];

            return $this->itemResponse($data);
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            Log::channel('seo-reports')->error('snapshot refresh failed', $context + [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            return $this->itemResponse([
                'ok' => false,
                'message' => 'Could not refresh dashboard metrics: '.$e->getMessage(),
            ]);
        }
    }

    // -- Reports -----------------------------------------------------------

    protected function reportPayload(string $key, array $meta): array
    {
        $file = $this->fileEntry($key, $meta);
        $file['html'] = $this->reportHtml($key);

        return $file;
    }

    /**
     * Public: App\Support\Seo\Reports\ReportRefresh reuses this (via
     * app(SeoReportController::class)->files()) to compute the set of
     * reports a refresh pass would run, rather than duplicating the
     * availability/freshness bookkeeping.
     *
     * @return array<int, array<string, mixed>>
     */
    public function files(): array
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

        $availability = ReportCapabilities::availability($key);
        $status = ! $availability['available']
            ? 'unavailable'
            : ($ageHours === null ? 'missing' : ($ageHours <= 24 ? 'fresh' : 'stale'));

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
            'available' => $availability['available'],
            'missing' => $availability['missing'],
            'unavailable_reason' => $availability['reason'],
        ];
    }

    protected function reportStats(array $files): array
    {
        $files = collect($files);
        $available = $files->where('available', true);
        $generated = $available->where('exists', true);

        return [
            'total' => $available->count(),
            'generated' => $generated->count(),
            'fresh' => $available->where('status', 'fresh')->count(),
            'stale' => $available->where('status', 'stale')->count(),
            'missing' => $available->where('status', 'missing')->count(),
            'unavailable' => $files->where('available', false)->count(),
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

    /** Trend windows the chart offers; 360 shows a whole year on a site that has one. */
    public const TREND_DAYS = [7, 14, 30, 60, 90, 180, 360];

    protected function normalizeTrendDays(int $days): int
    {
        return in_array($days, self::TREND_DAYS, true) ? $days : 14;
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

            ['rows' => $dailyClicks, 'through' => $through] = $this->dailySeries($trendDays);

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
                'through' => $through,
                'coverage' => $coverage,
                'rankings' => $rankings,
                'action_items' => $actionItems,
            ];
        });
    }

    /**
     * The chart rows: one per day with every metric for every channel, flat
     * keys (`gsc_clicks`, `bing_ctr`, `combined_impressions`…) because the
     * chart binds a line to a top-level field. Null means "this channel has
     * not reported this day", which the chart draws as a gap — a trailing
     * null is Search Console's two-day lag, a leading one is a channel that
     * was connected later. Neither is zero traffic, so neither is drawn as
     * zero.
     */
    protected function trendChartData(array $snapshot): array
    {
        return array_values($snapshot['daily_clicks'] ?? []);
    }

    /**
     * Day-by-day clicks, impressions, CTR and position per channel over the
     * last `$trendDays` days — ending on the LAST DAY THAT HAS DATA, not
     * today. Search Console publishes two to three days behind; a window
     * that ran to today filled the gap with zeros and read as a collapse.
     *
     * Per channel, a day inside its collected range with no row is 0 (a
     * quiet day); a day outside that range is null (not collected). Rows
     * at either end where no channel has anything are dropped, so a 360-day
     * request on a site with 100 days of history returns 100 rows, not 260
     * blank ones. Gaps in the middle stay as gaps.
     *
     * @return array{rows: list<array<string, mixed>>, through: ?string}
     */
    protected function dailySeries(int $trendDays): array
    {
        $gsc = $this->channelDaily('gsc_daily_totals', 'gsc_query_metrics');
        $bing = $this->channelDaily('bing_daily_totals', 'bing_traffic_stats');

        $through = max((string) $gsc['max'], (string) $bing['max']);
        if ($through === '') {
            return ['rows' => [], 'through' => null];
        }

        $end = Carbon::parse($through);
        $start = (clone $end)->subDays($trendDays - 1);
        $gscRows = $this->channelRows('gsc_daily_totals', 'gsc_query_metrics', $start, $end, $gsc['source']);
        $bingRows = $this->channelRows('bing_daily_totals', 'bing_traffic_stats', $start, $end, $bing['source']);
        $bingPositions = $this->bingDailyPositions($start, $end);

        $rows = [];
        for ($d = clone $start; $d->lte($end); $d->addDay()) {
            $day = $d->toDateString();
            $g = $this->channelDay($gscRows[$day] ?? null, $day, $gsc);
            $b = $this->channelDay($bingRows[$day] ?? null, $day, $bing);
            if ($b['position'] === null && isset($bingPositions[$day]) && $b['clicks'] !== null) {
                $b['position'] = $bingPositions[$day];
            }

            // Combined is a sum, and a sum with a missing term is not a
            // smaller sum — it is unknown. On the day Search Console has not
            // published yet, Google is null and Bing is 0, and adding them
            // drew the combined line plunging to zero on the last day of
            // every chart. So: any channel that reports at all in this
            // window and is null on this day makes combined null too, which
            // the chart draws as the same gap Google's own line shows. A
            // channel with no data anywhere (never connected) does not
            // block — a Google-only site still gets a combined line.
            $complete = ! ($gsc['max'] !== null && $g['clicks'] === null)
                && ! ($bing['max'] !== null && $b['clicks'] === null);

            $combinedClicks = $complete && ! ($g['clicks'] === null && $b['clicks'] === null)
                ? (int) $g['clicks'] + (int) $b['clicks'] : null;
            $combinedImpressions = $complete && ! ($g['impressions'] === null && $b['impressions'] === null)
                ? (int) $g['impressions'] + (int) $b['impressions'] : null;

            $rows[] = [
                'date' => $d->format('M j'),
                'day' => $day,
                'gsc_clicks' => $g['clicks'],
                'gsc_impressions' => $g['impressions'],
                'gsc_ctr' => $g['ctr'],
                'gsc_position' => $g['position'],
                'bing_clicks' => $b['clicks'],
                'bing_impressions' => $b['impressions'],
                'bing_ctr' => $b['ctr'],
                'bing_position' => $b['position'],
                'combined_clicks' => $combinedClicks,
                'combined_impressions' => $combinedImpressions,
                'combined_ctr' => $combinedImpressions === null ? null : ($combinedImpressions > 0 ? round($combinedClicks / $combinedImpressions * 100, 2) : 0.0),
                // Position does not add across engines; the panel says so.
                'combined_position' => null,
            ];
        }

        // Nothing at either end: not a quiet day, an uncollected one.
        $hasData = fn (array $r): bool => $r['gsc_clicks'] !== null || $r['bing_clicks'] !== null;
        while ($rows !== [] && ! $hasData($rows[0])) {
            array_shift($rows);
        }
        while ($rows !== [] && ! $hasData($rows[array_key_last($rows)])) {
            array_pop($rows);
        }

        return ['rows' => array_values($rows), 'through' => $end->format('M j')];
    }

    /**
     * Where a channel's data lives and how far it reaches. The totals table
     * is preferred; the per-query table is the fallback for a site synced
     * before totals existed.
     *
     * @return array{source: ?string, min: ?string, max: ?string}
     */
    protected function channelDaily(string $totalsTable, string $fallbackTable): array
    {
        foreach ([$totalsTable, $fallbackTable] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $range = Tenancy::table($table)->selectRaw('MIN(DATE(date)) as min_day, MAX(DATE(date)) as max_day')->first();
            if ($range && $range->max_day) {
                return ['source' => $table, 'min' => (string) $range->min_day, 'max' => (string) $range->max_day];
            }
        }

        return ['source' => null, 'min' => null, 'max' => null];
    }

    /** @return array<string, object> keyed by Y-m-d */
    protected function channelRows(string $totalsTable, string $fallbackTable, Carbon $start, Carbon $end, ?string $source): array
    {
        if ($source === null) {
            return [];
        }

        $query = Tenancy::table($source)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString());

        $rows = $source === $totalsTable
            ? $query->selectRaw('DATE(date) as day, SUM(clicks) as clicks, SUM(impressions) as impressions, '
                .(Schema::hasColumn($source, 'position') ? 'AVG(NULLIF(position, 0))' : 'NULL').' as position')
                ->groupBy('day')->get()
            : $query->selectRaw('DATE(date) as day, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(NULLIF(position, 0)) as position')
                ->groupBy('day')->get();

        return $rows->keyBy(fn ($r) => (string) $r->day)->all();
    }

    /**
     * Bing's daily totals carry no position; the per-query table does.
     *
     * @return array<string, float>
     */
    protected function bingDailyPositions(Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('bing_traffic_stats')) {
            return [];
        }

        return Tenancy::table('bing_traffic_stats')
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->selectRaw('DATE(date) as day, AVG(NULLIF(position, 0)) as position')
            ->groupBy('day')
            ->get()
            ->filter(fn ($r) => $r->position !== null)
            ->mapWithKeys(fn ($r) => [(string) $r->day => round((float) $r->position, 2)])
            ->all();
    }

    /**
     * One channel, one day: 0 for a quiet day inside the collected range,
     * null for a day the channel never reported.
     *
     * @param  array{min: ?string, max: ?string}  $range
     * @return array{clicks: ?int, impressions: ?int, ctr: ?float, position: ?float}
     */
    protected function channelDay(?object $row, string $day, array $range): array
    {
        $collected = $range['min'] !== null && $day >= $range['min'] && $day <= $range['max'];

        if (! $row && ! $collected) {
            return ['clicks' => null, 'impressions' => null, 'ctr' => null, 'position' => null];
        }

        $clicks = (int) ($row->clicks ?? 0);
        $impressions = (int) ($row->impressions ?? 0);
        $position = isset($row->position) && (float) $row->position > 0 ? round((float) $row->position, 2) : null;

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0,
            'position' => $position,
        ];
    }

    /**
     * Every query (or page) in the window, a page at a time — the screen's
     * two tables read this, sorted and paged, with the prior window on each
     * row. The snapshot's top_queries/top_pages stay a top ten for anything
     * still reading them.
     */
    public function topRowsPage(Request $request): JsonResponse
    {
        $dimension = $request->string('dimension')->toString() === 'page' ? 'page' : 'query';
        $days = $this->normalizeTopDays((int) $request->integer('days', 28));
        $sort = $request->string('sort', 'clicks')->toString();
        $direction = $request->string('dir', 'desc')->toString() === 'asc' ? 'asc' : 'desc';

        if (! Schema::hasTable('gsc_query_metrics')) {
            return $this->paginatedResponse(new LengthAwarePaginator([], 0, $this->perPage($request)), fn ($r) => $r);
        }

        $paginator = $this->topRowsQuery($dimension, $sort, $direction, $days)->paginate($this->perPage($request));
        $prior = $this->priorTopRows($dimension, $days, collect($paginator->items())->pluck('dim')->all());

        return $this->paginatedResponse($paginator, fn ($r) => $this->shapeTopRow($dimension, $r, $prior));
    }

    protected function topRows(string $dimension, string $sort, string $direction, int $topDays): array
    {
        if (! Schema::hasTable('gsc_query_metrics')) {
            return [];
        }

        $rows = $this->topRowsQuery($dimension, $sort, $direction, $topDays)->limit(10)->get();
        $prior = $this->priorTopRows($dimension, $topDays, $rows->pluck('dim')->all());

        return $rows->map(fn ($r) => $this->shapeTopRow($dimension, $r, $prior))->all();
    }

    /** One row per query (or page) over the window, sorted; the caller limits or paginates. */
    protected function topRowsQuery(string $dimension, string $sort, string $direction, int $topDays): Builder
    {
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
        $days = max(1, $topDays);

        return Tenancy::table('gsc_query_metrics')
            ->whereBetween('date', [Carbon::today()->subDays($days - 1)->toDateString(), Carbon::today()->toDateString()])
            ->groupBy($dimension)
            ->selectRaw("{$dimension} as dim, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position")
            ->orderByRaw("{$orderBy} {$direction}");
    }

    /**
     * The same keys over the window before this one, for the screen's ▲/▼
     * against the prior period. Only the keys on the board are fetched; a
     * key with nothing in the prior window gets no entry, and the screen
     * shows no chevron rather than a made-up zero.
     *
     * @param  array<int, string>  $keys
     * @return Collection<string, object>
     */
    protected function priorTopRows(string $dimension, int $topDays, array $keys): Collection
    {
        if ($keys === []) {
            return collect();
        }

        $days = max(1, $topDays);

        return Tenancy::table('gsc_query_metrics')
            ->whereBetween('date', [
                Carbon::today()->subDays(2 * $days - 1)->toDateString(),
                Carbon::today()->subDays($days)->toDateString(),
            ])
            ->whereIn($dimension, $keys)
            ->groupBy($dimension)
            ->selectRaw("{$dimension} as dim, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position")
            ->get()
            ->keyBy(fn ($r) => (string) $r->dim);
    }

    /** @param  Collection<string, object>  $prior */
    protected function shapeTopRow(string $dimension, object $r, Collection $prior): array
    {
        $shape = fn ($row) => [
            'clicks' => (int) $row->clicks,
            'impressions' => (int) $row->impressions,
            'ctr' => (int) $row->impressions > 0 ? round(((int) $row->clicks / (int) $row->impressions) * 100, 2) : 0.0,
            'position' => round((float) $row->position, 2),
        ];

        return [$dimension => (string) $r->dim] + $shape($r) + [
            'prior' => ($p = $prior->get((string) $r->dim)) ? $shape($p) : null,
        ];
    }

    /**
     * Site Pulse's `pulse` shape (SsSystems\Platform\Pulse\SnapshotBuilder,
     * bound as a singleton in AppServiceProvider) — cached 5 minutes per
     * tenant, same convention as every other snapshot section here
     * (Tenancy::cacheKey), but a shorter window per the kit's own spec
     * (docs/PULSE.md), matching dawnsellshomes.com's original.
     */
    protected function pulseSnapshot(): array
    {
        return Cache::remember(
            Tenancy::cacheKey('admin.seo-reports.pulse'),
            now()->addMinutes(5),
            fn () => app(SnapshotBuilder::class)->build(),
        );
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

                $prior = $this->priorHealth();

                return [
                    'score' => isset($data['score']) ? (int) $data['score'] : null,
                    'prior_score' => $prior['score'] ?? null,
                    'prior_as_of' => $prior['date'] ?? null,
                    'pillars' => $pillars,
                ];
            } catch (Throwable) {
                return ['score' => null, 'prior_score' => null, 'prior_as_of' => null, 'pillars' => []];
            }
        });
    }

    /**
     * The health ledger entry closest to 7 days before today, so the admin
     * can show a week-over-week trend chevron next to the health score.
     *
     * Tenant-scoped via SeoStorage, same convention as seo:health's
     * health-history.json — see SeoHealth::appendHealthLedger().
     *
     * Only entries aged 5–14 days count as "the prior week" — anything
     * closer reads as noise (yesterday's run), anything further is a stale
     * fallback that would misrepresent a week-over-week comparison.
     */
    /**
     * The score to compare this week's against, from the ledger seo:health
     * appends to (SeoStorage::path('reports/health-history.json')).
     *
     * Preferred: the entry closest to a week old, between 5 and 14 days.
     * When the ledger is younger than that — production's began on
     * 2026-09-17 — the oldest entry that is not today's stands in, so the
     * card still says which way the score has moved; the date travels with
     * it so the screen can say what it compared against.
     *
     * @return array{score: int, date: string}|null
     */
    protected function priorHealth(): ?array
    {
        $disk = Storage::disk('local');
        $path = SeoStorage::path('reports/health-history.json');

        if (! $disk->exists($path)) {
            return null;
        }

        try {
            $ledger = json_decode((string) $disk->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($ledger)) {
            return null;
        }

        $today = Carbon::today();
        $best = null;
        $bestDistance = null;
        $oldest = null;

        foreach ($ledger as $date => $score) {
            if (! is_string($date) || ! is_numeric($score)) {
                continue;
            }

            try {
                $age = (int) Carbon::createFromFormat('Y-m-d', $date)->startOfDay()->diffInDays($today);
            } catch (Throwable) {
                continue;
            }

            if ($age >= 1 && ($oldest === null || $age > $oldest['age'])) {
                $oldest = ['score' => (int) $score, 'date' => $date, 'age' => $age];
            }

            if ($age < 5 || $age > 14) {
                continue;
            }

            $distance = abs($age - 7);

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = ['score' => (int) $score, 'date' => $date];
            }
        }

        if ($best !== null) {
            return $best;
        }

        return $oldest ? ['score' => $oldest['score'], 'date' => $oldest['date']] : null;
    }

    protected function priorHealthScore(): ?int
    {
        return $this->priorHealth()['score'] ?? null;
    }

    /** Allowed comparison windows for the Clarity and Ranking cards. */
    protected function normalizeWindowDays(int $days): int
    {
        return in_array($days, [7, 14, 28], true) ? $days : 7;
    }

    protected function claritySnapshot(int $days = 7): array
    {
        if (! Schema::hasTable('clarity_daily_metrics')) {
            return ['available' => false, 'latest' => null, 'week' => [], 'prior' => [], 'scroll' => null, 'days' => $days, 'pages' => []];
        }

        return Cache::remember(Tenancy::cacheKey('seo_reports_clarity_v3_'.$days), 1800, function () use ($days): array {
            $latest = Tenancy::table('clarity_daily_metrics')->max('date');
            if (! $latest) {
                return ['available' => false, 'latest' => null, 'week' => [], 'prior' => [], 'scroll' => null, 'days' => $days, 'pages' => []];
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
                // The pages behind the tiles — the same selection the
                // recommendation engine acts on, so card and advice agree.
                'pages' => FrustratedPages::rising($days, 5),
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

            // The FAQ bank (kit 0.24.0): how many derived answers sit behind
            // /geo/answers.json and llms-full.txt, from how many pages, and
            // when a page was last built — zeros until the tables exist and
            // seo:faq-bank has run (this tenant's rows only, like every
            // store in AppServiceProvider::registerSeoTools()).
            $bank = app(FaqBankStore::class);

            return [
                'feeds' => $feeds,
                'faq_bank' => $bank->exists() ? $bank->summary() : ['answers' => 0, 'pages' => 0, 'last_generated_at' => null],
            ];
        });
    }

    protected function gscErrorSnapshot(): array
    {
        if (! Schema::hasTable('gsc_coverage_states')) {
            return [
                'available' => false,
                'totals' => ['tracked' => 0, 'problem' => 0, 'pass' => 0, 'not_indexed' => 0],
                'totals_prev' => null,
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
        $notIndexed = (int) GscCoverageState::query()->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])->count();

        return [
            'available' => true,
            'totals' => ['tracked' => $tracked, 'problem' => $problem, 'pass' => max(0, $tracked - $problem), 'not_indexed' => $notIndexed],
            // The tiles' ▲/▼: the same totals a week ago, from the inspection history.
            'totals_prev' => $this->coverageTotalsAsOf(now()->subDays(7)),
            'buckets' => $buckets,
            'latest_inspected' => $latestInspected ? Carbon::parse((string) $latestInspected)->diffForHumans() : null,
            'rows' => $rows,
        ];
    }

    /**
     * The same totals as they stood at $cutoff, rebuilt from the inspection
     * history: each currently tracked URL's last observation on or before
     * that moment. URLs with no observation by then were not on the board
     * yet and are left out, so "tracked" is honest too. Null without a
     * history table or when nothing had been observed by then — the screen
     * then shows no chevron rather than a made-up zero.
     *
     * @return array{tracked: int, problem: int, pass: int, not_indexed: int, as_of: string}|null
     */
    protected function coverageTotalsAsOf(Carbon $cutoff): ?array
    {
        if (! Schema::hasTable('gsc_coverage_state_history')) {
            return null;
        }

        $latest = Tenancy::table('gsc_coverage_state_history')
            ->where('observed_at', '<=', $cutoff)
            ->selectRaw('url, MAX(observed_at) as observed_at')
            ->groupBy('url');

        $rows = Tenancy::table('gsc_coverage_state_history as h')
            ->joinSub($latest, 'latest', fn ($join) => $join->on('latest.url', '=', 'h.url')->on('latest.observed_at', '=', 'h.observed_at'))
            ->whereIn('h.url', GscCoverageState::query()->select('url'))
            ->distinct()
            ->get(['h.url', 'h.verdict', 'h.coverage_state']);

        if ($rows->isEmpty()) {
            return null;
        }

        // The same reading of a row as the live totals above.
        $state = fn ($r) => strtolower((string) ($r->coverage_state ?? ''));
        $isProblem = function ($r) use ($state): bool {
            if ($r->verdict === null || strtoupper((string) $r->verdict) !== 'PASS') {
                return true;
            }
            foreach (['forbidden', 'not indexed', 'duplicate', 'soft 404'] as $needle) {
                if (str_contains($state($r), $needle)) {
                    return true;
                }
            }

            return false;
        };

        $tracked = $rows->count();
        $problem = $rows->filter($isProblem)->count();

        return [
            'tracked' => $tracked,
            'problem' => $problem,
            'pass' => max(0, $tracked - $problem),
            'not_indexed' => $rows->filter(fn ($r) => str_contains($state($r), 'not indexed'))->count(),
            'as_of' => $cutoff->toDateString(),
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
            $indexable = 0;
            $total = 0;
            foreach ($areas as $area) {
                foreach ($areaPageTypes as $pg) {
                    $total++;
                    if (AreaSeoPolicy::shouldIndex($area, $pg)) {
                        $indexable++;
                    }
                }
                foreach ($area->serviceSlugs() as $svc) {
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

    /** Public: ReportRefresh reuses this to bust the same cache regenerate() does. */
    public function searchSnapshotCacheKey(int $trendDays): string
    {
        return Tenancy::cacheKey('admin.seo-reports.search-snapshot.v2.'.$trendDays);
    }

    protected function percentDelta(int $current, int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
