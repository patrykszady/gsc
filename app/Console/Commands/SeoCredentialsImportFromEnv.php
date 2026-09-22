<?php

namespace App\Console\Commands;

use App\Support\Seo\SeoCredentialsImport;
use Illuminate\Console\Command;

/**
 * Thin CLI wrapper around App\Support\Seo\SeoCredentialsImport::run() — see
 * that class for the actual copy/report rules. Never prints a value — only
 * which of the six fields were imported, already stored, or absent from
 * both places. Site-scoped like every other seo:* command: run it once per
 * tenant, e.g. via `tenants:run "seo:credentials-import-from-env"
 * --site=...` for a tenant other than the current one.
 */
class SeoCredentialsImportFromEnv extends Command
{
    protected $signature = 'seo:credentials-import-from-env {--dry-run : List what would be imported without writing anything}';

    protected $description = 'Copy Bing/Clarity/PageSpeed/DataForSEO env credentials into this site\'s encrypted platform settings';

    public function handle(SeoCredentialsImport $import): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $import->run(dryRun: $dryRun);

        $this->report($dryRun ? 'Would import' : 'Imported', $result['imported']);
        $this->report('Already stored', $result['already_stored']);
        $this->report('Absent from env and storage', $result['absent']);

        if ($dryRun && $result['imported'] !== []) {
            $this->comment('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    /** @param  list<string>  $labels */
    protected function report(string $heading, array $labels): void
    {
        if ($labels === []) {
            return;
        }

        $this->line("{$heading}: ".implode(', ', $labels));
    }
}
