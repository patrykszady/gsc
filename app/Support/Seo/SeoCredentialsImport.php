<?php

namespace App\Support\Seo;

use App\Models\PlatformSetting;

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
 */
class SeoCredentialsImport
{
    /** Every source key this import understands, in report order. */
    public const SOURCES = ['bing', 'clarity', 'pagespeed', 'dataforseo'];

    /**
     * source key => [label => [storage key, the config()/env() value that
     * would seed it]], in the same order the original command reported
     * them.
     *
     * @return array<string, array<string, array{0: string, 1: mixed}>>
     */
    protected function fieldsBySource(): array
    {
        return [
            'bing' => [
                'Bing API key' => [BingSettings::SETTING_API_KEY, config('services.bing.webmaster_api_key')],
            ],
            'clarity' => [
                'Clarity project ID' => [ClaritySettings::SETTING_PROJECT_ID, config('services.microsoft.clarity.project_id')],
                'Clarity API token' => [ClaritySettings::SETTING_API_TOKEN, config('services.microsoft.clarity.api_token')],
            ],
            'pagespeed' => [
                'PageSpeed API key' => [PsiSettings::SETTING_API_KEY, config('services.google.pagespeed.api_key')],
            ],
            'dataforseo' => [
                'DataForSEO login' => [DataForSeoSettings::SETTING_LOGIN, config('services.dataforseo.login')],
                'DataForSEO password' => [DataForSeoSettings::SETTING_PASSWORD, config('services.dataforseo.password')],
            ],
        ];
    }

    /**
     * @param  list<string>  $sources  Among self::SOURCES; empty (the default) means all four.
     * @return array{imported: list<string>, already_stored: list<string>, absent: list<string>}
     */
    public function run(array $sources = [], bool $dryRun = false): array
    {
        $bySource = $this->fieldsBySource();
        $wanted = $sources === [] ? self::SOURCES : $sources;

        $imported = [];
        $alreadyStored = [];
        $absent = [];
        $importedClarity = false;

        foreach ($wanted as $source) {
            foreach ($bySource[$source] ?? [] as $label => [$key, $envValue]) {
                // Already stored wins even when env also has a value — this
                // never overwrites a value an admin (or a prior import)
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

                if ($source === 'clarity') {
                    $importedClarity = true;
                }
            }
        }

        // The public layout caches projectId() for a few minutes (it reads
        // on every page); without this a freshly-imported id would not show
        // up there, or in the sync, until that TTL expired. Only on a real
        // write, and only when Clarity was actually touched.
        if (! $dryRun && $importedClarity) {
            ClaritySettings::forgetProjectIdCache();
        }

        return [
            'imported' => $imported,
            'already_stored' => $alreadyStored,
            'absent' => $absent,
        ];
    }
}
