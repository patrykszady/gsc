<?php

namespace App\Console\Commands;

use App\Console\Commands\Seo\KitReportCommand;
use SsSystems\Platform\Reports\ContentGapReport;
use SsSystems\Platform\Reports\ReportResult;

/**
 * Content / topic-cluster gap planner — thin wrapper. The clustering
 * algorithm lives in the kit's ContentGapReport now; see
 * vendor/ss-systems/platform-kit/docs/REPORTS-PORTING.md.
 */
class SeoContentGap extends KitReportCommand
{
    protected $signature = 'seo:content-gap
        {--days=28 : Days back to aggregate}
        {--min-pos=8 : Minimum average position to consider}
        {--max-pos=20 : Maximum average position to consider}
        {--min-impressions=50 : Drop queries with fewer impressions}
        {--max-clusters=20 : Show top N clusters}
        {--markdown : Save report to storage/app/reports/content-gap.md}';

    protected $description = 'Surface low-hanging GSC queries (rank 8-20) and cluster them into content-brief candidates.';

    public function handle(ContentGapReport $report): int
    {
        $result = $report->generate([
            'days' => (int) $this->option('days'),
            'min_pos' => (float) $this->option('min-pos'),
            'max_pos' => (float) $this->option('max-pos'),
            'min_impressions' => (int) $this->option('min-impressions'),
            'max_clusters' => (int) $this->option('max-clusters'),
        ]);

        if ($result->status === ReportResult::STATUS_UNAVAILABLE) {
            return $this->unavailable($result);
        }

        $this->line($result->summary);

        $rows = array_map(
            fn (array $c, int $i): array => [
                '#' => $i + 1,
                'theme' => $c['theme'],
                'impr' => $c['impr'],
                'clicks' => $c['clicks'],
                'avg_pos' => $c['avg_pos'],
                'queries' => count($c['queries']),
            ],
            $result->data['clusters'],
            array_keys($result->data['clusters']),
        );
        $this->renderRows('Content-gap clusters (rank 8-20)', $rows, ['#', 'theme', 'impr', 'clicks', 'avg_pos', 'queries'], ['#', 'Theme', 'Impr', 'Clicks', 'Avg pos', 'Queries']);

        if ($this->option('markdown')) {
            $this->saveMarkdown(ContentGapReport::key(), $result->markdown, 'Saved: storage/app/reports/content-gap.md');
        }

        return self::SUCCESS;
    }
}
