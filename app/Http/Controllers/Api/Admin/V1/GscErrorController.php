<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Jobs\RunGscInspectBulkJob;
use App\Models\GscCoverageState;
use App\Models\GscRichResultIssue;
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
        GscCoverageState::query()
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
