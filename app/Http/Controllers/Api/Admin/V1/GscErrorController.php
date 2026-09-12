<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Jobs\RunGscInspectBulkJob;
use App\Jobs\RunGscInspectUrlsJob;
use App\Models\GscCoverageState;
use App\Models\GscRichResultIssue;
use App\Models\Tracked404;
use App\Services\GoogleSearchConsoleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ported from the Livewire admin's GscErrors page. refresh() dispatches
 * RunGscInspectBulkJob (a ~15 minute full-sitemap sweep) — never exercised
 * against the live app; verify with Queue::fake instead. prune-retired
 * deletes DB rows (of URLs that left the sitemap) — never exercised against
 * live data either, per the porting task's hard safety rules.
 */
class GscErrorController extends Controller
{
    use BuildsApiResponses;

    public function index(Request $request): JsonResponse
    {
        $query = $this->filteredQuery($request);

        $paginator = (clone $query)
            ->orderByRaw('COALESCE(last_changed_at, inspected_at) DESC')
            ->paginate($this->perPage($request, 25));

        $response = $this->paginatedResponse($paginator, fn (GscCoverageState $row) => $this->serializeRow($row));
        $payload = $response->getData(true);
        $payload['stats'] = $this->stats();
        $payload['enhancements'] = $this->enhancementSnapshot();
        $payload['reindex_report'] = $this->latestReindexReport();

        return response()->json($payload);
    }

    public function pruneRetired(Request $request): JsonResponse
    {
        $sitemapUrls = $this->sitemapUrlSet();
        if ($sitemapUrls === []) {
            return $this->itemResponse(['deleted' => 0, 'message' => 'Prune skipped: could not read sitemap.xml.']);
        }

        $deleted = 0;
        GscCoverageState::query()->where('source', 'sitemap')
            ->select(['id', 'url'])
            ->chunkById(500, function ($rows) use ($sitemapUrls, &$deleted): void {
                $ids = $rows->filter(fn ($r) => ! isset($sitemapUrls[(string) $r->url]))->pluck('id');
                if ($ids->isNotEmpty()) {
                    $deleted += GscCoverageState::query()->whereIn('id', $ids)->delete();
                }
            });

        $message = $deleted > 0
            ? "Pruned {$deleted} retired URL(s) no longer in the sitemap."
            : 'Nothing to prune — every tracked URL is in the current sitemap.';

        return $this->itemResponse(['deleted' => $deleted, 'message' => $message]);
    }

    public function refresh(Request $request): JsonResponse
    {
        try {
            RunGscInspectBulkJob::dispatch();

            return $this->itemResponse(['message' => 'Queued full sitemap inspection in background. Data will update as the job writes new results.']);
        } catch (\Throwable $e) {
            return $this->itemResponse(['message' => 'Failed to queue background refresh: '.$e->getMessage()], 500);
        }
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'gsc-errors-'.now()->format('Ymd-His').'.csv';
        $rows = $this->filteredQuery($request)
            ->orderByRaw('COALESCE(last_changed_at, inspected_at) DESC')
            ->limit(5000)
            ->get();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'url', 'path', 'issue', 'verdict', 'coverage_state', 'page_fetch_state',
                'robots_txt_state', 'indexing_state', 'last_crawl_time', 'inspected_at',
                'last_changed_at', 'consecutive_failures', 'user_canonical', 'google_canonical',
            ]);

            foreach ($rows as $row) {
                $path = parse_url((string) $row->url, PHP_URL_PATH) ?: '/';
                fputcsv($out, [
                    (string) $row->url,
                    (string) $path,
                    $this->classifyIssue((string) $row->coverage_state, (string) $row->page_fetch_state, (string) $row->verdict),
                    (string) ($row->verdict ?? 'UNKNOWN'),
                    (string) ($row->coverage_state ?? ''),
                    (string) ($row->page_fetch_state ?? ''),
                    (string) ($row->robots_txt_state ?? ''),
                    (string) ($row->indexing_state ?? ''),
                    optional($row->last_crawl_time)->toDateString(),
                    optional($row->inspected_at)->toDateTimeString(),
                    optional($row->last_changed_at)->toDateTimeString(),
                    (int) ($row->consecutive_failures ?? 0),
                    (string) ($row->user_canonical ?? ''),
                    (string) ($row->google_canonical ?? ''),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function serializeRow(GscCoverageState $row): array
    {
        $path = parse_url((string) $row->url, PHP_URL_PATH) ?: '/';

        return [
            'id' => $row->id,
            'url' => (string) $row->url,
            'path' => (string) $path,
            'issue' => $this->classifyIssue((string) $row->coverage_state, (string) $row->page_fetch_state, (string) $row->verdict),
            'verdict' => (string) ($row->verdict ?? 'UNKNOWN'),
            'coverage_state' => (string) ($row->coverage_state ?? ''),
            'page_fetch_state' => (string) ($row->page_fetch_state ?? ''),
            'robots_txt_state' => (string) ($row->robots_txt_state ?? ''),
            'indexing_state' => (string) ($row->indexing_state ?? ''),
            'last_crawl_time' => $row->last_crawl_time?->toDateString(),
            'inspected_at' => $row->inspected_at?->toIso8601String(),
            'last_changed_at' => $row->last_changed_at?->toIso8601String(),
            'consecutive_failures' => (int) ($row->consecutive_failures ?? 0),
        ];
    }

    /**
     * GET seo/gsc-errors/indexing — the Console's "Why pages aren't indexed"
     * table, from what this site knows: every coverage row (the nightly
     * sitemap sweep plus anything imported from a Console export or
     * inspected on demand) grouped by Google's own reason, plus two rows the
     * sweep cannot see because those URLs are not in the sitemap — the
     * paths Googlebot gets a 404 on (Tracked404) and the paths robots.txt
     * disallows. Each reason carries a sample of URLs.
     */
    public function indexing(Request $request): JsonResponse
    {
        $sample = max(1, min(50, (int) $request->integer('sample', 10)));

        $rows = GscCoverageState::query()
            ->selectRaw('COALESCE(NULLIF(coverage_state, ""), NULLIF(console_reason, ""), "Unknown") as reason, source, COUNT(*) as total')
            ->groupByRaw('COALESCE(NULLIF(coverage_state, ""), NULLIF(console_reason, ""), "Unknown"), source')
            ->orderByDesc('total')
            ->get();

        $reasons = [];
        foreach ($rows as $r) {
            $reason = (string) $r->reason;
            $reasons[$reason] ??= ['reason' => $reason, 'indexed' => str_starts_with(mb_strtolower($reason), 'submitted and indexed') || str_starts_with(mb_strtolower($reason), 'indexed'), 'total' => 0, 'sources' => [], 'sample' => []];
            $reasons[$reason]['total'] += (int) $r->total;
            $reasons[$reason]['sources'][(string) $r->source] = (int) $r->total;
        }
        foreach ($reasons as $reason => &$row) {
            $row['sample'] = GscCoverageState::query()
                ->where(fn ($q) => $q->where('coverage_state', $reason)->orWhere(fn ($q2) => $q2->whereNull('coverage_state')->where('console_reason', $reason)))
                ->orderByDesc('inspected_at')
                ->limit($sample)
                ->get()
                ->map(fn (GscCoverageState $c) => ['url' => $c->url, 'source' => $c->source, 'verdict' => $c->verdict, 'last_crawl_time' => $c->last_crawl_time?->toDateString(), 'inspected_at' => $c->inspected_at?->toIso8601String()])
                ->all();
        }
        unset($row);

        // Googlebot's 404s — URLs the sitemap never carried, so the sweep
        // never inspects them; the app's own tracker sees every hit.
        $base = rtrim((string) config('app.url'), '/');
        $googlebot404 = Tracked404::query()->where('user_agent', 'like', '%Googlebot%')->orderByDesc('hit_count');
        $notFoundTotal = (clone $googlebot404)->count();
        if ($notFoundTotal > 0) {
            $reasons['Not found (404), seen by Googlebot'] = [
                'reason' => 'Not found (404), seen by Googlebot',
                'indexed' => false,
                'total' => $notFoundTotal,
                'sources' => ['tracked' => $notFoundTotal],
                'sample' => (clone $googlebot404)->limit($sample)->get()->map(fn (Tracked404 $t) => ['url' => $base.$t->path, 'source' => 'tracked', 'hits' => (int) $t->hit_count, 'last_seen_at' => optional($t->last_seen_at)->toIso8601String()])->all(),
            ];
        }

        // What robots.txt keeps Google out of — by rule, since a blocked URL
        // is never in the sitemap to be inspected.
        $disallow = [];
        $robots = is_file(public_path('robots.txt')) ? (string) file_get_contents(public_path('robots.txt')) : '';
        if (preg_match_all('/^Disallow:\s*(\S+)/mi', $robots, $m)) {
            $disallow = array_values(array_unique($m[1]));
        }
        if ($disallow !== []) {
            $reasons['Blocked by robots.txt'] = [
                'reason' => 'Blocked by robots.txt',
                'indexed' => false,
                'total' => count($disallow),
                'sources' => ['robots' => count($disallow)],
                'sample' => array_map(fn ($path) => ['url' => $base.$path, 'source' => 'robots'], array_slice($disallow, 0, $sample)),
            ];
        }

        uasort($reasons, fn ($a, $b) => [$a['indexed'] ? 1 : 0, -$a['total']] <=> [$b['indexed'] ? 1 : 0, -$b['total']]);

        return $this->itemResponse([
            'reasons' => array_values($reasons),
            'sources' => [
                'sitemap' => GscCoverageState::query()->where('source', 'sitemap')->count(),
                'console' => GscCoverageState::query()->where('source', 'console')->count(),
                'tracked' => GscCoverageState::query()->where('source', 'tracked')->count(),
            ],
            'latest_inspected' => ($latest = GscCoverageState::query()->max('inspected_at')) ? Carbon::parse($latest)->toIso8601String() : null,
        ]);
    }

    /**
     * POST seo/gsc-errors/import — the CSV Search Console's Page indexing
     * screen exports for one reason (a "URL" column, one URL per row).
     * Every URL of this site in it is queued for URL Inspection and kept as
     * a coverage row tagged source=console with that reason.
     */
    public function importConsoleExport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'csv' => ['required', 'string', 'max:2000000'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        $urls = [];
        $skipped = 0;
        foreach (preg_split('/\r\n|\r|\n/', $data['csv']) as $line) {
            $cells = str_getcsv($line);
            $candidate = null;
            foreach ($cells as $cell) {
                $cell = trim((string) $cell, " \t\"'");
                if (preg_match('#^https?://#i', $cell)) {
                    $candidate = $cell;
                    break;
                }
            }
            if ($candidate === null) {
                continue;
            }
            if ($host && ! str_ends_with(mb_strtolower((string) parse_url($candidate, PHP_URL_HOST)), mb_strtolower($host))) {
                $skipped++;

                continue;
            }
            $urls[$candidate] = true;
        }
        $urls = array_keys($urls);

        if ($urls === []) {
            return $this->itemResponse(['queued' => 0, 'skipped' => $skipped, 'message' => 'No URLs of this site in that file — export a reason from Search Console\'s Page indexing screen (Export → CSV).']);
        }

        RunGscInspectUrlsJob::dispatch(array_slice($urls, 0, 1500), 'console', $data['reason'] ?? null);

        return $this->itemResponse([
            'queued' => count($urls),
            'skipped' => $skipped,
            'message' => count($urls).' URL(s) queued for URL Inspection — about '.max(1, (int) ceil(count($urls) * 2 / 60)).' minute(s); they will appear here with their reason.',
        ]);
    }

    /** POST seo/gsc-errors/inspect — one URL through the URL Inspection API right now, and kept as a coverage row. */
    public function inspect(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url', 'max:2000']]);
        $service = app(GoogleSearchConsoleService::class);
        $site = (string) config('services.google.search_console.site_url');

        $result = $service->inspectUrl($site, $data['url']);
        if ($result === null) {
            return $this->itemResponse(['ok' => false, 'message' => $service->getLastError()['message'] ?? 'Search Console did not answer.'], 502);
        }

        $index = $result['indexStatusResult'] ?? [];
        $existing = GscCoverageState::query()->where('url', $data['url'])->first();
        GscCoverageState::updateOrCreate(['url' => $data['url']], [
            'source' => $existing?->source ?? 'console',
            'verdict' => $index['verdict'] ?? null,
            'coverage_state' => $index['coverageState'] ?? null,
            'robots_txt_state' => $index['robotsTxtState'] ?? null,
            'indexing_state' => $index['indexingState'] ?? null,
            'page_fetch_state' => $index['pageFetchState'] ?? null,
            'sitemap_url' => $index['sitemap'][0] ?? ($existing?->sitemap_url),
            'last_crawl_time' => isset($index['lastCrawlTime']) ? Carbon::parse($index['lastCrawlTime']) : null,
            'user_canonical' => $index['userCanonical'] ?? null,
            'google_canonical' => $index['googleCanonical'] ?? null,
            'inspected_at' => now(),
            'last_changed_at' => $existing?->last_changed_at ?? now(),
            'consecutive_failures' => ($index['verdict'] ?? null) !== 'PASS' ? (($existing->consecutive_failures ?? 0) + 1) : 0,
        ]);

        return $this->itemResponse([
            'ok' => true,
            'url' => $data['url'],
            'index' => [
                'verdict' => $index['verdict'] ?? null,
                'coverage_state' => $index['coverageState'] ?? null,
                'robots_txt_state' => $index['robotsTxtState'] ?? null,
                'indexing_state' => $index['indexingState'] ?? null,
                'page_fetch_state' => $index['pageFetchState'] ?? null,
                'last_crawl_time' => $index['lastCrawlTime'] ?? null,
                'crawled_as' => $index['crawledAs'] ?? null,
                'user_canonical' => $index['userCanonical'] ?? null,
                'google_canonical' => $index['googleCanonical'] ?? null,
                'sitemap' => $index['sitemap'] ?? [],
                'referring_urls' => $index['referringUrls'] ?? [],
            ],
            'rich_results' => $result['richResultsResult'] ?? null,
            'mobile_usability' => $result['mobileUsabilityResult'] ?? null,
            'inspection_result_link' => $result['inspectionResultLink'] ?? null,
        ]);
    }

    /** GET seo/gsc-errors/sitemaps — the property's sitemaps as Search Console reports them. */
    public function sitemaps(): JsonResponse
    {
        $service = app(GoogleSearchConsoleService::class);
        $site = (string) config('services.google.search_console.site_url');
        $list = $service->listSitemaps($site);

        return $this->itemResponse([
            'site_url' => $site,
            'available' => $list !== null,
            'message' => $list === null ? ($service->getLastError()['message'] ?? 'Search Console did not answer.') : null,
            'sitemaps' => array_map(fn (array $s) => [
                'path' => $s['path'] ?? null,
                'type' => $s['type'] ?? null,
                'last_submitted' => $s['lastSubmitted'] ?? null,
                'last_downloaded' => $s['lastDownloaded'] ?? null,
                'is_pending' => (bool) ($s['isPending'] ?? false),
                'is_sitemaps_index' => (bool) ($s['isSitemapsIndex'] ?? false),
                'errors' => (int) ($s['errors'] ?? 0),
                'warnings' => (int) ($s['warnings'] ?? 0),
                'contents' => array_map(fn (array $c) => ['type' => $c['type'] ?? null, 'submitted' => (int) ($c['submitted'] ?? 0), 'indexed' => (int) ($c['indexed'] ?? 0)], $s['contents'] ?? []),
            ], $list ?? []),
        ]);
    }

    /** POST seo/gsc-errors/sitemaps — submit (or re-submit) a sitemap URL of this site. */
    public function submitSitemap(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url', 'max:2000']]);
        $service = app(GoogleSearchConsoleService::class);
        $ok = $service->submitSitemap((string) config('services.google.search_console.site_url'), $data['url']);

        return $this->itemResponse(['ok' => $ok, 'message' => $ok ? 'Submitted — Google will re-read it shortly.' : ($service->getLastError()['message'] ?? 'Search Console refused the sitemap.')], $ok ? 200 : 502);
    }

    /** DELETE seo/gsc-errors/sitemaps — remove a sitemap from the property. */
    public function deleteSitemap(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url', 'max:2000']]);
        $service = app(GoogleSearchConsoleService::class);
        $ok = $service->deleteSitemap((string) config('services.google.search_console.site_url'), $data['url']);

        return $this->itemResponse(['ok' => $ok, 'message' => $ok ? 'Removed from Search Console.' : ($service->getLastError()['message'] ?? 'Search Console refused.')], $ok ? 200 : 502);
    }

    /**
     * @return array{tracked:int,problem:int,pass:int,retired:int,latest_inspected:?string,sitemap_urls:int,inspection_coverage_pct:int}
     */
    protected function stats(): array
    {
        $tracked = GscCoverageState::query()->count();
        $problem = GscCoverageState::query()->where(function ($q) {
            $q->where('verdict', '!=', 'PASS')
                ->orWhereNull('verdict')
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%forbidden%'])
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%duplicate%'])
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%soft 404%']);
        })->count();

        $sitemapUrls = $this->sitemapUrlSet();
        $trackedInSitemap = 0;
        $retired = 0;
        if ($sitemapUrls !== []) {
            GscCoverageState::query()->select(['id', 'url'])->chunkById(500, function ($rows) use ($sitemapUrls, &$trackedInSitemap, &$retired): void {
                foreach ($rows as $r) {
                    isset($sitemapUrls[(string) $r->url]) ? $trackedInSitemap++ : $retired++;
                }
            });
        }

        $stats = [
            'tracked' => (int) $tracked,
            'problem' => (int) $problem,
            'pass' => max(0, (int) $tracked - (int) $problem),
            'retired' => $retired,
            'latest_inspected' => ($latest = GscCoverageState::query()->max('inspected_at'))
                ? Carbon::parse($latest)->diffForHumans()
                : null,
            'sitemap_urls' => count($sitemapUrls),
        ];

        $stats['inspection_coverage_pct'] = $stats['sitemap_urls'] > 0
            ? min(100, (int) round(($trackedInSitemap / max(1, $stats['sitemap_urls'])) * 100))
            : 0;

        return $stats;
    }

    protected function latestReindexReport(): array
    {
        $path = 'reports/reindex-problem-pages-last.md';
        if (! Storage::disk('local')->exists($path)) {
            return [
                'available' => false, 'generated' => null, 'mode' => null, 'detected' => null,
                'submitted' => null, 'excluded_410' => null, 'excluded_not_in_sitemap' => null, 'body' => null,
            ];
        }

        $content = (string) Storage::disk('local')->get($path);

        return [
            'available' => true,
            'generated' => $this->extractReportValue($content, '- Generated: '),
            'mode' => $this->extractReportValue($content, '- Mode: '),
            'detected' => $this->extractReportInt($content, '- Detected URLs: **'),
            'submitted' => $this->extractReportInt($content, '- Submitted URLs: **'),
            'excluded_410' => $this->extractReportInt($content, '- Excluded (410): **'),
            'excluded_not_in_sitemap' => $this->extractReportInt($content, '- Excluded (not in sitemap): **'),
            'body' => trim($content),
        ];
    }

    protected function extractReportValue(string $content, string $prefix): ?string
    {
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            if (str_starts_with($line, $prefix)) {
                return trim(substr($line, strlen($prefix)));
            }
        }

        return null;
    }

    protected function extractReportInt(string $content, string $prefix): ?int
    {
        $value = $this->extractReportValue($content, $prefix);
        if ($value === null) {
            return null;
        }

        return preg_match('/(\d+)/', $value, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * @return array{available:bool,total_issues:int,affected_urls:int,product_issues:int,shopping_issues:int,latest_inspected:?string,by_type:array<int,array{type:string,count:int}>}
     */
    protected function enhancementSnapshot(): array
    {
        if (! Schema::hasTable('gsc_rich_result_issues')) {
            return [
                'available' => false, 'total_issues' => 0, 'affected_urls' => 0,
                'product_issues' => 0, 'shopping_issues' => 0, 'latest_inspected' => null, 'by_type' => [],
            ];
        }

        $q = GscRichResultIssue::query();
        $total = (int) (clone $q)->count();
        $affected = (int) (clone $q)->distinct('url')->count('url');
        $product = (int) (clone $q)->whereRaw('LOWER(COALESCE(rich_result_type, "")) like ?', ['%product%'])->count();
        $shopping = (int) (clone $q)
            ->where(function ($b) {
                $b->whereRaw('LOWER(COALESCE(rich_result_type, "")) like ?', ['%merchant%'])
                    ->orWhereRaw('LOWER(COALESCE(issue_message, "")) like ?', ['%merchant%'])
                    ->orWhereRaw('LOWER(COALESCE(issue_message, "")) like ?', ['%shipping%'])
                    ->orWhereRaw('LOWER(COALESCE(issue_message, "")) like ?', ['%return policy%'])
                    ->orWhereRaw('LOWER(COALESCE(issue_message, "")) like ?', ['%gtin%'])
                    ->orWhereRaw('LOWER(COALESCE(issue_message, "")) like ?', ['%mpn%'])
                    ->orWhereRaw('LOWER(COALESCE(issue_message, "")) like ?', ['%sku%']);
            })->count();

        $byType = (clone $q)
            ->selectRaw('COALESCE(rich_result_type, "Unknown") as type, COUNT(*) as n')
            ->groupBy('type')
            ->orderByDesc('n')
            ->limit(8)
            ->get()
            ->map(fn ($r) => ['type' => (string) $r->type, 'count' => (int) $r->n])
            ->all();

        $latest = (clone $q)->max('inspected_at');

        return [
            'available' => true,
            'total_issues' => $total,
            'affected_urls' => $affected,
            'product_issues' => $product,
            'shopping_issues' => $shopping,
            'latest_inspected' => $latest ? Carbon::parse((string) $latest)->diffForHumans() : null,
            'by_type' => $byType,
        ];
    }

    protected function filteredQuery(Request $request): Builder
    {
        $scope = $request->string('scope', 'problems')->toString();
        $search = $request->string('search', '')->toString();
        $verdictFilter = $request->string('verdict', 'all')->toString();
        $issueFilter = $request->string('issue', 'all')->toString();

        $query = GscCoverageState::query();

        if ($scope === 'problems') {
            $query->where(function ($q) {
                $q->where('verdict', '!=', 'PASS')
                    ->orWhereNull('verdict')
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%forbidden%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%duplicate%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%soft 404%'])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', ['%server%'])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', ['%not found%'])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', ['%redirect%']);
            });
        }

        if ($search !== '') {
            $term = '%'.str_replace('%', '\\%', strtolower(trim($search))).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(url) like ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(verdict, "")) like ?', [$term]);
            });
        }

        if ($verdictFilter !== 'all') {
            if ($verdictFilter === 'unknown') {
                $query->whereNull('verdict');
            } else {
                $query->where('verdict', strtoupper($verdictFilter));
            }
        }

        if ($issueFilter !== 'all') {
            $query->where(fn ($q) => $this->applyIssueFilter($q, $issueFilter));
        }

        return $query;
    }

    protected function applyIssueFilter(Builder $query, string $issueFilter): void
    {
        if ($issueFilter === 'blocked') {
            $query->where(function ($q) {
                $q->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%forbidden%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%robots%'])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', ['%robots%']);
            });

            return;
        }

        if ($issueFilter === 'not_indexed') {
            $query->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%']);

            return;
        }

        if ($issueFilter === 'duplicate') {
            $query->where(function ($q) {
                $q->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%duplicate%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%canonical%']);
            });

            return;
        }

        if ($issueFilter === 'soft_404') {
            $query->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%soft 404%']);

            return;
        }

        if ($issueFilter === 'fetch_error') {
            $query->where(function ($q) {
                $q->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%server error%'])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', ['%server%'])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', ['%not found%'])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', ['%redirect%']);
            });

            return;
        }

        if ($issueFilter === 'other') {
            $query->where(function ($q) {
                $q->whereRaw('LOWER(COALESCE(coverage_state, "")) not like ?', ['%forbidden%'])
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) not like ?', ['%not indexed%'])
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) not like ?', ['%duplicate%'])
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) not like ?', ['%soft 404%'])
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) not like ?', ['%canonical%'])
                    ->whereRaw('LOWER(COALESCE(page_fetch_state, "")) not like ?', ['%server%'])
                    ->whereRaw('LOWER(COALESCE(page_fetch_state, "")) not like ?', ['%not found%'])
                    ->whereRaw('LOWER(COALESCE(page_fetch_state, "")) not like ?', ['%redirect%']);
            });
        }
    }

    /** @return array<string, true> sitemap URLs as a lookup set */
    protected function sitemapUrlSet(): array
    {
        $path = public_path('sitemap.xml');
        if (! is_file($path)) {
            return [];
        }

        $xml = @simplexml_load_string((string) file_get_contents($path));
        if (! $xml || ! isset($xml->url)) {
            return [];
        }

        $set = [];
        foreach ($xml->url as $u) {
            $set[(string) $u->loc] = true;
        }

        return $set;
    }

    protected function classifyIssue(string $coverageState, string $pageFetchState, string $verdict): string
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
}
