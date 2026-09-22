<?php

namespace App\Console\Commands;

use App\Models\PlatformSetting;
use App\Support\Seo\BingSettings;
use App\Support\Seo\ClaritySettings;
use App\Support\Seo\DataForSeoSettings;
use App\Support\Seo\PsiSettings;
use Illuminate\Console\Command;

/**
 * Copies every Bing/Clarity/PageSpeed/DataForSEO credential this box's env
 * or config already has into this site's encrypted platform_settings, so
 * the env values can be deleted once every tenant that needs them has its
 * own admin-stored row (CLAUDE.md's "credentials must leave the
 * environment" rule). Never prints a value — only which of the six fields
 * were imported, already stored, or absent from both places. Site-scoped
 * like every other seo:* command: run it once per tenant, e.g. via
 * `tenants:run "seo:credentials-import-from-env" --site=...` for a tenant
 * other than the current one.
 */
class SeoCredentialsImportFromEnv extends Command
{
    protected $signature = 'seo:credentials-import-from-env {--dry-run : List what would be imported without writing anything}';

    protected $description = 'Copy Bing/Clarity/PageSpeed/DataForSEO env credentials into this site\'s encrypted platform settings';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // label => [storage key, the config()/env() value that would seed it]
        $fields = [
            'Bing API key' => [BingSettings::SETTING_API_KEY, config('services.bing.webmaster_api_key')],
            'Clarity project ID' => [ClaritySettings::SETTING_PROJECT_ID, config('services.microsoft.clarity.project_id')],
            'Clarity API token' => [ClaritySettings::SETTING_API_TOKEN, config('services.microsoft.clarity.api_token')],
            'PageSpeed API key' => [PsiSettings::SETTING_API_KEY, config('services.google.pagespeed.api_key')],
            'DataForSEO login' => [DataForSeoSettings::SETTING_LOGIN, config('services.dataforseo.login')],
            'DataForSEO password' => [DataForSeoSettings::SETTING_PASSWORD, config('services.dataforseo.password')],
        ];

        $imported = [];
        $alreadyStored = [];
        $absent = [];

        foreach ($fields as $label => [$key, $envValue]) {
            // Already stored wins even when env also has a value — this
            // command never overwrites a value an admin (or a prior import)
            // already put in platform_settings.
            if (filled(PlatformSetting::get($key))) {
                $alreadyStored[] = $label;

                continue;
            }

            $envValue = is_string($envValue) ? trim($envValue) : $envValue;
            if (! filled($envValue)) {
                $absent[] = $label;

                continue;
            }

            if (! $dryRun) {
                PlatformSetting::put($key, (string) $envValue);
            }
            $imported[] = $label;
        }

        $this->report($dryRun ? 'Would import' : 'Imported', $imported);
        $this->report('Already stored', $alreadyStored);
        $this->report('Absent from env and storage', $absent);

        if ($dryRun && $imported !== []) {
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
