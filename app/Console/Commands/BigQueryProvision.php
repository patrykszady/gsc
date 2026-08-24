<?php

namespace App\Console\Commands;

use App\Services\GoogleCloudService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Provision the Google-Cloud half of a per-site Search Console BigQuery
 * export, and print exactly what to paste into the one form that has no API.
 *
 *   php artisan bigquery:provision                     current/default site
 *   php artisan tenants:run "bigquery:provision" --site=jpeterson
 *
 * Idempotent: safe to re-run any time. The Search Console "Bulk data export"
 * form (Settings → Bulk data export) is UI-only and owner-only by Google's
 * design — this command shrinks a per-site setup to that single 2-minute
 * step, done by whoever holds verified ownership (the platform, in the
 * standard onboarding).
 */
class BigQueryProvision extends Command
{
    protected $signature = 'bigquery:provision {--dataset= : Override the dataset id}';

    protected $description = 'Provision the platform GCP project + per-site dataset for the Search Console bulk export.';

    public function handle(GoogleCloudService $gcp): int
    {
        if (! $gcp->isConfigured()) {
            $this->error('GCP_PROJECT_ID / GCP_CREDENTIALS_PATH not configured. One-time platform setup:');
            $this->line('  1. console.cloud.google.com → new project (e.g. "ss-systems-search-data") → copy its PROJECT ID');
            $this->line('  2. IAM & Admin → Service Accounts → Create ("search-provisioner")');
            $this->line('     Roles: Service Usage Admin · Project IAM Admin · BigQuery Admin');
            $this->line('  3. Keys → Add key → JSON → download');
            $this->line('  4. .env: GCP_PROJECT_ID=<project-id>  GCP_CREDENTIALS_PATH=/path/to/key.json');

            return self::FAILURE;
        }

        $site = \App\Models\Site::current();
        $slug = $site?->slug ?: 'default';
        // BigQuery dataset ids allow [A-Za-z0-9_] only.
        $dataset = (string) ($this->option('dataset') ?: 'searchconsole_' . Str::of($slug)->replace('-', '_'));

        $this->info("Provisioning for site '{$slug}' → project {$gcp->projectId()}, dataset {$dataset}");

        foreach ([
            'BigQuery API enabled' => fn () => $gcp->enableBigQuery(),
            'Search Console exporter granted (jobUser + dataEditor)' => fn () => $gcp->grantSearchConsoleExporter(),
            "Dataset {$dataset} exists (location US)" => fn () => $gcp->ensureDataset($dataset),
        ] as $label => $step) {
            if ($step()) {
                $this->info("  ✓ {$label}");
            } else {
                $this->error("  ✗ {$label}: " . ($gcp->getLastError() ?? 'unknown'));

                return self::FAILURE;
            }
        }

        // Remember the mapping for the (future) reader side.
        \App\Support\Tenancy::table('platform_settings')->updateOrInsert(
            ['site_id' => $site?->id, 'key' => 'bigquery.dataset'],
            ['value' => $dataset, 'updated_at' => now(), 'created_at' => now()],
        );

        $this->newLine();
        $this->info('Google Cloud side complete. The ONE remaining step (no API exists — verified owner, in the browser):');
        $this->line('  Search Console → property → Settings → Bulk data export');
        $this->line("    Cloud project ID : {$gcp->projectId()}");
        $this->line("    Dataset name     : {$dataset}");
        $this->line('    Dataset location : United States (US)');
        $this->line('  → Continue. First daily drop lands within 48h, automatic forever after.');

        return self::SUCCESS;
    }
}
