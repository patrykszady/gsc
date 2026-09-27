<?php

namespace App\Support\Seo;

use App\Models\PlatformSetting;
use SsSystems\Platform\Seo\Credentials\Contracts\CredentialStore;
use SsSystems\Platform\Seo\Credentials\CredentialEnvImport;

/**
 * Copies whichever of the four SEO credential sources (Bing/Clarity/
 * PageSpeed/DataForSEO) this box's env or config already has into this
 * site's encrypted platform_settings, so the env values can eventually be
 * deleted once every tenant that needs them has its own admin-stored row
 * (CLAUDE.md's "credentials must leave the environment" rule).
 *
 * Shared by two doors that must behave identically: the
 * seo:credentials-import-from-env console command (a thin wrapper around
 * run() now) and the central admin's POST platforms/seo-credentials/import
 * endpoint (PlatformsController::importSeoCredentialsFromEnv()), reached
 * from the SEO screen's Connect Services modal so nobody has to ssh in to
 * run the command. Same rules either way: a value already stored wins over
 * env even when env also has one, an env value is trimmed before being
 * judged blank, and a credential VALUE is never logged, printed or
 * returned — only which labels were imported, already stored, or absent
 * from both places.
 *
 * That loop now lives once in the kit
 * (SsSystems\Platform\Seo\Credentials\CredentialEnvImport) — every site
 * ran the identical logic, only the table of fields below ever differed.
 * This class stays local because the config KEY NAMES genuinely differ
 * per site (see BingSettings/etc.'s own docblocks), and so does the
 * onImported callback (only gsc/jpeterson-design bust Clarity's cache).
 */
class SeoCredentialsImport
{
    /** Every source key this import understands, in report order. */
    public const SOURCES = ['bing', 'clarity', 'pagespeed', 'dataforseo'];

    /**
     * @param  list<string>  $sources  Among self::SOURCES; empty (the default) means all four.
     * @return array{imported: list<string>, already_stored: list<string>, absent: list<string>}
     */
    public function run(array $sources = [], bool $dryRun = false): array
    {
        $sources = $sources === [] ? self::SOURCES : $sources;

        $import = new CredentialEnvImport($this->store());

        return $import->run(
            $this->fields($sources),
            $dryRun,
            fn (string $source) => $source === 'clarity' ? ClaritySettings::forgetProjectIdCache() : null,
        );
    }

    /** This site's PlatformSetting table as the kit's CredentialStore seam — never falls back to config()/env(). */
    protected function store(): CredentialStore
    {
        return new class implements CredentialStore
        {
            public function get(string $key): ?string
            {
                return PlatformSetting::get($key);
            }

            public function put(string $key, string $value): void
            {
                PlatformSetting::put($key, $value);
            }
        };
    }

    /**
     * @param  list<string>  $sources
     * @return list<array{label: string, key: string, env: mixed, source: string}>
     */
    protected function fields(array $sources): array
    {
        $bySource = [
            'bing' => [
                ['label' => 'Bing API key', 'key' => BingSettings::SETTING_API_KEY, 'env' => config('services.bing.webmaster_api_key')],
            ],
            'clarity' => [
                ['label' => 'Clarity project ID', 'key' => ClaritySettings::SETTING_PROJECT_ID, 'env' => config('services.microsoft.clarity.project_id')],
                ['label' => 'Clarity API token', 'key' => ClaritySettings::SETTING_API_TOKEN, 'env' => config('services.microsoft.clarity.api_token')],
            ],
            'pagespeed' => [
                ['label' => 'PageSpeed API key', 'key' => PsiSettings::SETTING_API_KEY, 'env' => config('services.google.pagespeed.api_key')],
            ],
            'dataforseo' => [
                ['label' => 'DataForSEO login', 'key' => DataForSeoSettings::SETTING_LOGIN, 'env' => config('services.dataforseo.login')],
                ['label' => 'DataForSEO password', 'key' => DataForSeoSettings::SETTING_PASSWORD, 'env' => config('services.dataforseo.password')],
            ],
        ];

        $rows = [];

        // Iterate SOURCES' own fixed order rather than $sources' order, so
        // the report is always bing/clarity/pagespeed/dataforseo no matter
        // what order the caller listed them in.
        foreach (self::SOURCES as $source) {
            if (! in_array($source, $sources, true)) {
                continue;
            }

            foreach ($bySource[$source] as $row) {
                $row['source'] = $source;
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
