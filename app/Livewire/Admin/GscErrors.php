<?php

namespace App\Livewire\Admin;

use App\Models\GscCoverageState;
use App\Models\GscRichResultIssue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('components.layouts.admin')]
#[Title('GSC Errors')]
class GscErrors extends Component
{
    use WithPagination;

    public ?string $flash = null;

    #[Url]
    public string $search = '';

    #[Url]
    public string $issueFilter = 'all'; // all, blocked, not_indexed, duplicate, soft_404, fetch_error, other

    #[Url]
    public string $verdictFilter = 'all'; // all, PASS, FAIL, NEUTRAL, unknown

    #[Url]
    public string $scope = 'problems'; // problems, all

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedIssueFilter(): void
    {
        $this->resetPage();
    }

    public function updatedVerdictFilter(): void
    {
        $this->resetPage();
    }

    public function updatedScope(): void
    {
        $this->resetPage();
    }

    public function refreshInBackground(): void
    {
        try {
            // Dedicated job (not Artisan::queue) so the long sweep gets its own
            // timeout instead of the worker's 60s default. See RunGscInspectBulkJob.
            \App\Jobs\RunGscInspectBulkJob::dispatch();

            $this->flash = 'Queued full sitemap inspection in background. Data will update as the job writes new results.';
        } catch (\Throwable $e) {
            $this->flash = 'Failed to queue background refresh: ' . $e->getMessage();
        }
    }

    public function exportCsv(): StreamedResponse
    {
        $filename = 'gsc-errors-' . now()->format('Ymd-His') . '.csv';
        $rows = $this->filteredQuery()
            ->orderByRaw('COALESCE(last_changed_at, inspected_at) DESC')
            ->limit(5000)
            ->get();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'url',
                'path',
                'issue',
                'verdict',
                'coverage_state',
                'page_fetch_state',
                'robots_txt_state',
                'indexing_state',
                'last_crawl_time',
                'inspected_at',
                'last_changed_at',
                'consecutive_failures',
                'user_canonical',
                'google_canonical',
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
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function render()
    {
        $query = $this->filteredQuery();

        $errors = (clone $query)
            ->orderByRaw('COALESCE(last_changed_at, inspected_at) DESC')
            ->paginate(25);

        $tracked = GscCoverageState::query()->count();
        $problem = GscCoverageState::query()->where(function ($q) {
            $q->where('verdict', '!=', 'PASS')
                ->orWhereNull('verdict')
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%forbidden%'])
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%duplicate%'])
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%soft 404%']);
        })->count();

        $stats = [
            'tracked' => (int) $tracked,
            'problem' => (int) $problem,
            'pass' => max(0, (int) $tracked - (int) $problem),
            'latest_inspected' => ($latest = GscCoverageState::query()->max('inspected_at'))
                ? \Illuminate\Support\Carbon::parse($latest)->diffForHumans()
                : null,
            'sitemap_urls' => $this->countSitemapUrls(),
        ];

        $stats['inspection_coverage_pct'] = $stats['sitemap_urls'] > 0
            ? (int) round(($stats['tracked'] / max(1, $stats['sitemap_urls'])) * 100)
            : 0;

        $enhancements = $this->enhancementSnapshot();
        $reindexReport = $this->latestReindexReport();

        return view('livewire.admin.gsc-errors', [
            'errors' => $errors,
            'stats' => $stats,
            'enhancements' => $enhancements,
            'reindexReport' => $reindexReport,
        ]);
    }

    /**
     * @return array{
     *   available:bool,
     *   generated:?string,
     *   mode:?string,
     *   detected:?int,
     *   submitted:?int,
     *   excluded410:?int,
     *   excludedNotInSitemap:?int,
     *   body:?string
     * }
     */
    protected function latestReindexReport(): array
    {
        $path = 'reports/reindex-problem-pages-last.md';
        if (! Storage::disk('local')->exists($path)) {
            return [
                'available' => false,
                'generated' => null,
                'mode' => null,
                'detected' => null,
                'submitted' => null,
                'excluded410' => null,
                'excludedNotInSitemap' => null,
                'body' => null,
            ];
        }

        $content = (string) Storage::disk('local')->get($path);

        return [
            'available' => true,
            'generated' => $this->extractReportValue($content, '- Generated: '),
            'mode' => $this->extractReportValue($content, '- Mode: '),
            'detected' => $this->extractReportInt($content, '- Detected URLs: **'),
            'submitted' => $this->extractReportInt($content, '- Submitted URLs: **'),
            'excluded410' => $this->extractReportInt($content, '- Excluded (410): **'),
            'excludedNotInSitemap' => $this->extractReportInt($content, '- Excluded (not in sitemap): **'),
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

        if (preg_match('/(\d+)/', $value, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @return array{
     *   available:bool,
     *   total_issues:int,
     *   affected_urls:int,
     *   product_issues:int,
     *   shopping_issues:int,
     *   latest_inspected:?string,
     *   by_type:array<int,array{type:string,count:int}>
     * }
     */
    protected function enhancementSnapshot(): array
    {
        if (! Schema::hasTable('gsc_rich_result_issues')) {
            return [
                'available' => false,
                'total_issues' => 0,
                'affected_urls' => 0,
                'product_issues' => 0,
                'shopping_issues' => 0,
                'latest_inspected' => null,
                'by_type' => [],
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
            'latest_inspected' => $latest ? \Illuminate\Support\Carbon::parse((string) $latest)->diffForHumans() : null,
            'by_type' => $byType,
        ];
    }

    protected function countSitemapUrls(): int
    {
        $path = public_path('sitemap.xml');
        if (! is_file($path)) {
            return 0;
        }

        $xml = @simplexml_load_string((string) file_get_contents($path));
        if (! $xml || ! isset($xml->url)) {
            return 0;
        }

        return count($xml->url);
    }

    protected function filteredQuery(): Builder
    {
        $query = GscCoverageState::query();

        if ($this->scope === 'problems') {
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

        if ($this->search !== '') {
            $term = '%' . str_replace('%', '\\%', strtolower(trim($this->search))) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(url) like ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(verdict, "")) like ?', [$term]);
            });
        }

        if ($this->verdictFilter !== 'all') {
            if ($this->verdictFilter === 'unknown') {
                $query->whereNull('verdict');
            } else {
                $query->where('verdict', strtoupper($this->verdictFilter));
            }
        }

        if ($this->issueFilter !== 'all') {
            $query->where(function ($q) {
                $this->applyIssueFilter($q, $this->issueFilter);
            });
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

    protected function classifyIssue(string $coverageState, string $pageFetchState, string $verdict): string
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
}
