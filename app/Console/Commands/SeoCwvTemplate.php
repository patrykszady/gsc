<?php

namespace App\Console\Commands;

use App\Console\Commands\Seo\KitReportCommand;
use SsSystems\Platform\Reports\CwvTemplateReport;

/**
 * Per-template Core Web Vitals tracker — thin wrapper. The p75 aggregation
 * and regression detection live in the kit's CwvTemplateReport now; see
 * vendor/ss-systems/platform-kit/docs/REPORTS-PORTING.md.
 */
class SeoCwvTemplate extends KitReportCommand
{
    protected $signature = 'seo:cwv-template
        {--window=7 : Days in each comparison window}
        {--lcp-regress=150 : Flag templates whose p75 LCP grew by >=N ms (field/CrUX-backed)}
        {--lab-lcp-regress=500 : LCP threshold (ms) for lab-only buckets; lab mobile LCP is synthetic and noisy}
        {--inp-regress=40 : Flag templates whose p75 INP grew by >=N ms}
        {--cls-regress=0.02 : Flag templates whose p75 CLS grew by >=N}
        {--min-samples=10 : Require at least N samples in BOTH windows before flagging a regression}
        {--markdown : Save report to storage/app/reports/cwv-template.md}';

    protected $description = 'Aggregate Core Web Vitals per page template and surface week-over-week regressions.';

    public function handle(CwvTemplateReport $report): int
    {
        $result = $report->generate([
            'window' => (int) $this->option('window'),
            'lcp_regress' => (int) $this->option('lcp-regress'),
            'lab_lcp_regress' => (int) $this->option('lab-lcp-regress'),
            'inp_regress' => (int) $this->option('inp-regress'),
            'cls_regress' => (float) $this->option('cls-regress'),
            'min_samples' => (int) $this->option('min-samples'),
        ]);

        $this->line($result->summary);
        $this->renderRows('p75 CWV per template', $result->data['rows'], ['template', 'strategy', 'samples', 'lcp', 'inp', 'cls', 'perf'], ['Template', 'Strategy', 'Samples', 'LCP p75', 'INP p75', 'CLS p75', 'Perf']);

        if (! empty($result->data['alerts'])) {
            $this->newLine();
            $this->line('<fg=yellow>--- Regressions ---</>');
            foreach ($result->data['alerts'] as $alert) {
                $this->line('  • '.$alert);
            }
        } else {
            $this->newLine();
            $this->info('No CWV regressions detected.');
        }

        $this->logAlert($result->data['alert'] ?? null, ['alerts' => $result->data['alerts']]);

        if ($this->option('markdown')) {
            $this->saveMarkdown(CwvTemplateReport::key(), $result->markdown, 'Saved: storage/app/reports/cwv-template.md');
        }

        return self::SUCCESS;
    }
}
