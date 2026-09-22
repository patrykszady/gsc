<?php

namespace App\Console\Commands;

use App\Console\Commands\Seo\KitReportCommand;
use SsSystems\Platform\Reports\ClarityHealthReport;
use SsSystems\Platform\Reports\ReportResult;

/**
 * Health check for the Microsoft Clarity integration — thin wrapper. The
 * spike-detection algorithm and every console line live in the kit's
 * ClarityHealthReport now (data['console_lines'] reproduces exactly what
 * this command used to print); see
 * vendor/ss-systems/platform-kit/docs/REPORTS-PORTING.md.
 */
class SeoClarityHealth extends KitReportCommand
{
    protected $signature = 'seo:clarity-health
        {--markdown : Save markdown report to storage/app/reports/clarity-health.md}';

    protected $description = 'Health check for Microsoft Clarity integration and latest metric freshness';

    public function handle(ClarityHealthReport $report): int
    {
        $result = $report->generate();

        if ($result->status === ReportResult::STATUS_UNAVAILABLE) {
            return $this->unavailable($result);
        }

        foreach ($result->data['console_lines'] as $line) {
            $this->line($line);
        }

        if ($this->option('markdown')) {
            $this->saveMarkdown(ClarityHealthReport::key(), $result->markdown, 'Saved: storage/app/private/reports/clarity-health.md');
        }

        $spike = $result->data['spike'] ?? null;
        if (is_array($spike) && isset($spike['alert_message'])) {
            logger()->warning((string) $spike['alert_message'], ['spike' => $spike['summary'] ?? null]);
        }

        return $result->data['exit'] === 'failure' ? self::FAILURE : self::SUCCESS;
    }
}
