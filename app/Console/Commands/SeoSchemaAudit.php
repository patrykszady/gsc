<?php

namespace App\Console\Commands;

use App\Console\Commands\Seo\KitReportCommand;
use SsSystems\Platform\Reports\ReportResult;
use SsSystems\Platform\Reports\SchemaAuditReport;

/**
 * JSON-LD schema coverage/validity sweep — thin wrapper. The crawl, @type
 * collection and per-type validation live in the kit's SchemaAuditReport
 * now; see vendor/ss-systems/platform-kit/docs/REPORTS-PORTING.md. The
 * original's `--sitemap` override has no equivalent (SiteCatalog owns
 * fetching this site's own sitemap.xml internally) — only `--urls` and
 * `--limit` survive, per ReportRegistry's documented options.
 */
class SeoSchemaAudit extends KitReportCommand
{
    protected $signature = 'seo:schema-audit
        {--urls= : CSV of explicit URLs to audit (overrides the sitemap)}
        {--limit=80 : Max URLs}
        {--markdown : Save markdown report to storage/app/reports/schema-audit.md}';

    protected $description = 'Audit JSON-LD schema coverage and validity across our own sitemap.';

    public function handle(SchemaAuditReport $report): int
    {
        $result = $report->generate([
            'urls' => (string) $this->option('urls'),
            'limit' => (int) $this->option('limit'),
        ]);

        if ($result->status === ReportResult::STATUS_ERROR) {
            return $this->reportError($result);
        }

        $this->line($result->summary);

        $coverageRows = array_map(fn (array $c): array => ['type' => $c['type'], 'urls' => $c['urls']], $result->data['coverage']);
        $this->renderRows('Coverage by @type', $coverageRows, ['type', 'urls'], ['@type', 'URLs']);

        if (! empty($result->data['missing_schema'])) {
            $this->newLine();
            $this->line('<fg=yellow>--- URLs with NO JSON-LD ('.count($result->data['missing_schema']).') ---</>');
            foreach (array_slice($result->data['missing_schema'], 0, 10) as $u) {
                $this->line('  '.$u);
            }
        }

        if (! empty($result->data['parse_errors'])) {
            $this->newLine();
            $this->line('<fg=red>--- JSON-LD parse errors ('.count($result->data['parse_errors']).') ---</>');
            foreach (array_slice($result->data['parse_errors'], 0, 10, true) as $url => $errs) {
                $this->line('  '.$url.' — '.implode('; ', $errs));
            }
        }

        $this->logAlert($result->data['alert'] ?? null, [
            'missing_schema' => count($result->data['missing_schema']),
            'parse_errors' => count($result->data['parse_errors']),
            'validation_issues' => count($result->data['validation_issues']),
            'duplicate_ids' => count($result->data['duplicate_ids']),
        ]);

        if ($this->option('markdown')) {
            $this->saveMarkdown(SchemaAuditReport::key(), $result->markdown, 'Saved: storage/app/reports/schema-audit.md');
        }

        return self::SUCCESS;
    }
}
