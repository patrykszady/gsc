<?php

namespace App\Console\Commands;

use App\Console\Commands\Seo\KitReportCommand;
use SsSystems\Platform\Reports\AreaPagesAuditReport;
use SsSystems\Platform\Reports\ReportResult;

/**
 * Per-area landing-page audit — thin wrapper. The thin-content threshold and
 * Jaccard near-duplicate clustering live in the kit's AreaPagesAuditReport
 * now; see vendor/ss-systems/platform-kit/docs/REPORTS-PORTING.md.
 */
class SeoAreaPagesAudit extends KitReportCommand
{
    protected $signature = 'seo:area-pages-audit
        {--sample=10 : Number of areas to sample (0 = all)}
        {--variants=home,contact,services-kitchen,services-bathroom,services-home : CSV of page variants per area}
        {--thin=350 : Word-count threshold considered "thin"}
        {--sim=0.85 : Jaccard threshold for near-duplicate clustering (0..1)}
        {--shingle=5 : N-gram size for similarity}
        {--markdown : Save markdown report to storage/app/reports/area-pages-audit.md}';

    protected $description = 'Audit per-area landing pages for thin content and near-duplicates.';

    public function handle(AreaPagesAuditReport $report): int
    {
        $result = $report->generate([
            'sample' => (int) $this->option('sample'),
            'variants' => (string) $this->option('variants'),
            'thin' => (int) $this->option('thin'),
            'sim' => (float) $this->option('sim'),
            'shingle' => (int) $this->option('shingle'),
        ]);

        if ($result->status === ReportResult::STATUS_ERROR) {
            return $this->reportError($result);
        }
        if ($result->status === ReportResult::STATUS_UNAVAILABLE) {
            return $this->unavailable($result);
        }

        $this->line($result->summary);
        $this->renderRows('Variant overview', $result->data['variant_overview'], ['variant', 'pages', 'min', 'avg', 'max'], ['Variant', 'Pages', 'Min', 'Avg', 'Max']);

        $this->newLine();
        $this->warn('Thin pages: '.$result->data['thin_count']);
        foreach (array_slice($result->data['thin'], 0, 20) as $t) {
            $this->line("  [{$t['words']}w] {$t['url']}");
        }

        $this->newLine();
        $this->warn('Near-duplicate clusters: '.$result->data['clusters_count']);
        foreach ($result->data['clusters'] as $variant => $groups) {
            foreach ($groups as $i => $members) {
                $this->line('  '.$variant.' cluster #'.($i + 1).' ('.count($members).' areas): '.implode(', ', array_slice($members, 0, 6)));
            }
        }

        if (! empty($result->data['fetch_failures'])) {
            $this->newLine();
            $this->error('Fetch failures: '.$result->data['fetch_failures_count']);
            foreach (array_slice($result->data['fetch_failures'], 0, 10) as $u) {
                $this->line('  '.$u);
            }
        }

        if ($this->option('markdown')) {
            $this->saveMarkdown(AreaPagesAuditReport::key(), $result->markdown, 'Saved: storage/app/private/reports/area-pages-audit.md');
        }

        return self::SUCCESS;
    }
}
