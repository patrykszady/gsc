<?php

namespace App\Console\Commands;

use App\Console\Commands\Seo\KitReportCommand;
use SsSystems\Platform\Reports\HealthCheckReport;
use SsSystems\Platform\Reports\ReportResult;

/**
 * Local SEO health-check: per-URL composite score (0-100). Thin wrapper —
 * every check and its weight live in the kit's HealthCheckReport now; see
 * vendor/ss-systems/platform-kit/docs/REPORTS-PORTING.md. The original's
 * `--sitemap` override has no equivalent (SiteCatalog owns the sitemap
 * fetch internally); `--urls`, `--limit` and `--min-score` survive.
 */
class SeoHealthCheck extends KitReportCommand
{
    protected $signature = 'seo:health-check
        {--urls= : CSV of explicit URLs (overrides the sitemap)}
        {--limit=60 : Max URLs}
        {--min-score=80 : Fail (non-zero exit) if any URL scores below this}
        {--markdown : Save markdown report to storage/app/reports/health-check.md}';

    protected $description = 'Composite per-URL Local SEO health-check (0–100 score).';

    public function handle(HealthCheckReport $report): int
    {
        $result = $report->generate([
            'urls' => (string) $this->option('urls'),
            'limit' => (int) $this->option('limit'),
            'min_score' => (int) $this->option('min-score'),
        ]);

        if ($result->status === ReportResult::STATUS_UNAVAILABLE) {
            return $this->unavailable($result);
        }

        $this->newLine();
        $this->info('=== Health-check summary ===');
        $this->line($result->summary);

        $rows = array_map(function (array $r): array {
            $failing = array_filter($r['breakdown'], fn (array $v): bool => ! $v['ok']);
            $parts = [];
            foreach ($failing as $key => $v) {
                $parts[] = "{$key}: {$v['note']}";
            }

            return ['score' => $r['score'], 'url' => $r['url'], 'failing' => $parts === [] ? '—' : implode('; ', $parts)];
        }, array_slice($result->data['rows'], 0, 10));
        $this->renderRows('Worst 10', $rows, ['score', 'url', 'failing'], ['Score', 'URL', 'Failing checks']);

        if ($this->option('markdown') && $result->markdown !== '') {
            $this->saveMarkdown(HealthCheckReport::key(), $result->markdown, 'Saved: storage/app/private/reports/health-check.md');
        }

        return $result->status === ReportResult::STATUS_DEGRADED ? self::FAILURE : self::SUCCESS;
    }
}
