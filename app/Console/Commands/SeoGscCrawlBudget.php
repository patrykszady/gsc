<?php

namespace App\Console\Commands;

use App\Models\GscCoverageState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Crawl-budget report derived from the data we collect via `seo:gsc-inspect-bulk`
 * + `seo:reindex-problem-pages`. Surfaces pages Google hasn't recrawled in N days
 * — strong signal of crawl-budget waste or low perceived value.
 *
 * Note: Google's "Crawl stats" UI page (which shows raw Googlebot HTTP code distribution)
 * is NOT exposed via the Search Console API. The only programmatic equivalents are
 * (a) the URL Inspection lastCrawlTime field, which we use here, and (b) raw server
 * access logs from Cloudflare Logpush (Enterprise) / origin nginx. This report uses (a).
 */
class SeoGscCrawlBudget extends Command
{
    protected $signature = 'seo:gsc-crawl-budget
        {--stale-days=45 : Threshold for "stale" crawl in days}
        {--very-stale-days=90 : Threshold for "very stale" crawl in days}
        {--markdown : Write reports/gsc-crawl-budget.md}';

    protected $description = 'Crawl-budget waste report: pages Google has not recrawled in N days.';

    public function handle(): int
    {
        $stale = (int) $this->option('stale-days');
        $verystale = (int) $this->option('very-stale-days');

        $total = GscCoverageState::count();
        if ($total === 0) {
            $this->warn('No coverage data yet. Run: php artisan seo:gsc-inspect-bulk --markdown');
            return self::FAILURE;
        }

        $never = GscCoverageState::whereNull('last_crawl_time')->count();
        $verystaleCount = GscCoverageState::whereNotNull('last_crawl_time')
            ->where('last_crawl_time', '<', now()->subDays($verystale))->count();
        $staleCount = GscCoverageState::whereNotNull('last_crawl_time')
            ->where('last_crawl_time', '<', now()->subDays($stale))
            ->where('last_crawl_time', '>=', now()->subDays($verystale))->count();
        $fresh = $total - $never - $staleCount - $verystaleCount;

        $this->info("Coverage rows: {$total}");
        $this->info("  Fresh (<{$stale}d):           {$fresh}");
        $this->info("  Stale ({$stale}-{$verystale}d):    {$staleCount}");
        $this->info("  Very stale (>={$verystale}d):    {$verystaleCount}");
        $this->info("  Never crawled / unknown:    {$never}");

        $worst = GscCoverageState::query()
            ->whereNotNull('last_crawl_time')
            ->orderBy('last_crawl_time')
            ->limit(50)
            ->get(['url', 'last_crawl_time', 'verdict', 'coverage_state']);

        foreach ($worst->take(10) as $w) {
            $this->line(sprintf(
                '  %s  %s  %s',
                optional($w->last_crawl_time)->toDateString(),
                $w->verdict ?? '?',
                $w->url
            ));
        }

        if ($this->option('markdown')) {
            $this->writeReport($total, $fresh, $staleCount, $verystaleCount, $never, $worst, $stale, $verystale);
        }

        // More than 25% stale coverage signals a crawl-budget issue. Log it as a
        // warning (like the other seo:* monitors) rather than exiting non-zero —
        // the scheduler turned the old FAILURE exit into a weekly ERROR with a
        // full stack trace, which read like a crash instead of a metric. Note the
        // ratio includes intentionally-noindexed URLs Google will rightly stop
        // crawling, so it runs structurally high after the area-page pruning.
        $staleTotal = $staleCount + $verystaleCount + $never;
        if ($staleTotal > ($total * 0.25)) {
            $pct = round($staleTotal / max(1, $total) * 100);
            $this->warn("Stale coverage above threshold: {$pct}% ({$staleTotal}/{$total}).");
            \Illuminate\Support\Facades\Log::warning('seo:gsc-crawl-budget stale coverage above threshold', [
                'stale' => $staleTotal,
                'total' => $total,
                'percent' => $pct,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * @param \Illuminate\Support\Collection<int,GscCoverageState> $worst
     */
    protected function writeReport(
        int $total, int $fresh, int $staleCount, int $verystaleCount, int $never,
        $worst, int $stale, int $verystaleDays
    ): void {
        $lines = [];
        $lines[] = '# GSC Crawl-budget report';
        $lines[] = '';
        $lines[] = '_Generated: ' . now()->toIso8601String() . '_';
        $lines[] = '';
        $lines[] = '_Source: `gsc_coverage_states.last_crawl_time` (URL Inspection API). Google\'s Crawl Stats UI is not exposed via API; this report approximates it._';
        $lines[] = '';
        $lines[] = '## Distribution';
        $lines[] = '';
        $lines[] = '| Bucket | Count | % |';
        $lines[] = '|---|---:|---:|';
        foreach ([
            "Fresh (<{$stale}d)" => $fresh,
            "Stale ({$stale}-{$verystaleDays}d)" => $staleCount,
            "Very stale (≥{$verystaleDays}d)" => $verystaleCount,
            'Never crawled / unknown' => $never,
        ] as $label => $n) {
            $pct = $total > 0 ? number_format(100 * $n / $total, 1) : '0.0';
            $lines[] = "| {$label} | {$n} | {$pct}% |";
        }
        $lines[] = '';
        $lines[] = '## Top 50 staleness offenders';
        $lines[] = '';
        $lines[] = '| Last crawled | Verdict | Coverage | URL |';
        $lines[] = '|---|---|---|---|';
        foreach ($worst as $w) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                optional($w->last_crawl_time)->toDateString() ?? '–',
                $w->verdict ?? '?',
                $w->coverage_state ?? '?',
                $w->url
            );
        }
        $lines[] = '';
        $lines[] = '## Remediation';
        $lines[] = '';
        $lines[] = '- Push the worst offenders through `php artisan seo:reindex-problem-pages --urls=<URL>` to nudge Googlebot.';
        $lines[] = '- Add fresh internal links to deeply-stale pages so they appear more important.';
        $lines[] = '- For very-stale, low-value URLs consider `noindex` to reclaim crawl budget for the rest.';
        Storage::disk('local')->put('reports/gsc-crawl-budget.md', implode("\n", $lines));
        $this->info('Wrote reports/gsc-crawl-budget.md');
    }
}
