<?php

namespace App\Console\Commands;

use App\Console\Commands\Seo\KitReportCommand;
use SsSystems\Platform\Reports\InternalLinkSuggestReport;
use SsSystems\Platform\Reports\ReportResult;

/**
 * Internal-link opportunity finder — thin wrapper. The crawl/anchor-match
 * algorithm lives in the kit's InternalLinkSuggestReport now; see
 * vendor/ss-systems/platform-kit/docs/REPORTS-PORTING.md.
 */
class SeoInternalLinkSuggest extends KitReportCommand
{
    protected $signature = 'seo:internal-link-suggest
        {--limit=80 : Max URLs to scan as sources}
        {--target-limit=60 : Max target pages to consider}
        {--min-anchor=4 : Minimum anchor-word length (single-word anchors discouraged)}
        {--max-per-page=5 : Cap suggestions per source page}
        {--markdown : Save report to storage/app/reports/internal-link-suggest.md}';

    protected $description = 'Suggest internal links where target-page keywords appear unlinked in other pages\' body copy.';

    public function handle(InternalLinkSuggestReport $report): int
    {
        $result = $report->generate([
            'limit' => (int) $this->option('limit'),
            'target_limit' => (int) $this->option('target-limit'),
            'min_anchor' => (int) $this->option('min-anchor'),
            'max_per_page' => (int) $this->option('max-per-page'),
        ]);

        if ($result->status === ReportResult::STATUS_ERROR) {
            return $this->reportError($result);
        }

        $this->line($result->summary);

        $suggestions = $result->data['suggestions'];
        $shown = 0;
        foreach ($suggestions as $src => $list) {
            if ($shown >= 8) {
                $this->line('  … +'.(count($suggestions) - 8).' more pages (see markdown report)');
                break;
            }
            $this->line($src);
            foreach ($list as $s) {
                $this->line(sprintf('  → %s  [anchor: "%s"]', $s['target'], $s['anchor']));
            }
            $shown++;
        }

        if ($this->option('markdown')) {
            $this->saveMarkdown(InternalLinkSuggestReport::key(), $result->markdown, 'Saved: storage/app/reports/internal-link-suggest.md');
        }

        return self::SUCCESS;
    }
}
