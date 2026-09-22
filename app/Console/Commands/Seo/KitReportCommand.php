<?php

namespace App\Console\Commands\Seo;

use App\Support\SeoStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Reports\ReportResult;

/**
 * Shared mechanics for the ten thin artisan wrappers around the kit's
 * SsSystems\Platform\Reports\* classes (vendor/ss-systems/platform-kit's
 * docs/REPORTS-PORTING.md, "How a site wraps a report"). The algorithm
 * moved to the kit; what stays here per command is this site's own artisan
 * surface — its exact $signature/options, what it prints, and its exit-code
 * rule, which genuinely differs report to report (see each command's own
 * handle()) — so each command keeps a plain, readable handle() rather than
 * being forced through one generic dispatcher.
 */
abstract class KitReportCommand extends Command
{
    /**
     * Write a markdown result to the SAME tenant-scoped path the admin's
     * SeoReportController and SeoReportRun read back
     * (App\Support\SeoStorage::path("reports/{key}.md")), and print the
     * "Saved: ..." line the old command printed.
     */
    protected function saveMarkdown(string $key, string $markdown, string $savedLine): void
    {
        Storage::disk('local')->put(SeoStorage::path("reports/{$key}.md"), $markdown);
        $this->info($savedLine);
    }

    /**
     * The three-key alert shape ContentDecayReport / CwvTemplateReport /
     * SchemaAuditReport hand back in data['alert'] in place of calling
     * logger() themselves (the kit has no logger dependency — see
     * docs/REPORTS-PORTING.md, "The cache capability: no logger in the
     * kit"). A suppressed or level-less alert logs nothing, exactly like
     * the original commands' own Cache::add()-gated branches.
     *
     * @param  array<string, mixed>|null  $alert
     * @param  array<string, mixed>  $context
     */
    protected function logAlert(?array $alert, array $context = []): void
    {
        if ($alert === null || ($alert['suppressed'] ?? false) || ($alert['level'] ?? null) === null) {
            return;
        }

        match ($alert['level']) {
            'warning' => logger()->warning((string) $alert['message'], $context),
            'info' => logger()->info((string) $alert['message'], $context),
            default => null,
        };
    }

    /**
     * Two different things arrive as "unavailable". A missing capability
     * (`missing` names it) is a real refusal: exit 1 so a schedule notices.
     * An empty `missing` means the report ran and found nothing to report
     * on (content-gap with no rank-band queries, area-pages-audit with no
     * areas): the old commands treated that as a warning and exit 0, and a
     * nightly schedule must not log a failure for a quiet week.
     */
    protected function unavailable(ReportResult $result): int
    {
        if ($result->missing === []) {
            $this->warn($result->summary);

            return self::SUCCESS;
        }

        $this->error($result->summary);

        return self::FAILURE;
    }

    /** Something actually broke while generating the report. */
    protected function reportError(ReportResult $result): int
    {
        $this->error((string) $result->error);

        return self::FAILURE;
    }

    /**
     * A plain console table from a list of associative rows — the
     * structural equivalent of each old command's own renderTable(); exact
     * column formatting is no longer pixel-identical (the kit report's
     * `data` is now the source of truth for the admin/API too), but every
     * row the old table showed is still here.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $cols
     * @param  list<string>  $headers
     */
    protected function renderRows(string $title, array $rows, array $cols, array $headers): void
    {
        $this->newLine();
        $this->line("<fg=cyan>--- {$title} (".count($rows).') ---</>');

        if ($rows === []) {
            $this->line('  (none)');

            return;
        }

        $table = array_map(
            fn (array $row): array => array_map($this->formatCell(...), array_map(fn (string $c) => $row[$c] ?? null, $cols)),
            $rows,
        );

        $this->table($headers, $table);
    }

    private function formatCell(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_float($value) => number_format($value, 2),
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => implode(', ', $value),
            default => (string) $value,
        };
    }
}
