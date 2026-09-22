<?php

namespace App\Console\Commands;

use App\Console\Commands\Seo\KitReportCommand;
use SsSystems\Platform\Reports\ContentDecayReport;
use SsSystems\Platform\Reports\ReportResult;

/**
 * Content-decay detector — thin wrapper. The algorithm (compare recent vs
 * prior window, thresholds, markdown) lives in the kit's ContentDecayReport
 * now; see vendor/ss-systems/platform-kit/docs/REPORTS-PORTING.md.
 */
class SeoContentDecay extends KitReportCommand
{
    protected $signature = 'seo:content-decay
        {--window=28 : Days in each comparison window}
        {--min-impressions=50 : Ignore pages with fewer impressions in the prior window}
        {--click-drop=20 : Flag pages losing >=N% clicks}
        {--pos-drop=2 : Flag pages whose average position worsened by >=N}
        {--limit=40 : Max rows per section}
        {--markdown : Save report to storage/app/reports/content-decay.md}';

    protected $description = 'Find pages with declining clicks / impressions / position from Search Console data.';

    public function handle(ContentDecayReport $report): int
    {
        $result = $report->generate([
            'window' => (int) $this->option('window'),
            'min_impressions' => (int) $this->option('min-impressions'),
            'click_drop' => (int) $this->option('click-drop'),
            'pos_drop' => (float) $this->option('pos-drop'),
            'limit' => (int) $this->option('limit'),
        ]);

        if ($result->status === ReportResult::STATUS_ERROR) {
            return $this->reportError($result);
        }
        if ($result->status === ReportResult::STATUS_UNAVAILABLE) {
            return $this->unavailable($result);
        }

        $this->line($result->summary);
        $this->renderRows('Click drops', $result->data['click_decay'], ['page', 'p_clicks', 'r_clicks', 'click_pct'], ['Page', 'Prior clicks', 'Recent clicks', '% change']);
        $this->renderRows('Position regressions', $result->data['pos_decay'], ['page', 'p_pos', 'r_pos', 'pos_delta'], ['Page', 'Prior pos', 'Recent pos', 'Δ pos']);

        $this->logAlert($result->data['alert'] ?? null, [
            'click_drops' => $result->data['click_drops'],
            'meaningful_click_drops' => $result->data['meaningful_click_drops'],
            'pos_regressions' => $result->data['pos_regressions'],
        ]);

        if ($this->option('markdown')) {
            $this->saveMarkdown(ContentDecayReport::key(), $result->markdown, 'Saved: storage/app/reports/content-decay.md');
        }

        return self::SUCCESS;
    }
}
