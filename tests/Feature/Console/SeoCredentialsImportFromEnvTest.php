<?php

namespace Tests\Feature\Console;

use App\Models\PlatformSetting;
use App\Support\Seo\BingSettings;
use App\Support\Seo\ClaritySettings;
use App\Support\Seo\DataForSeoSettings;
use App\Support\Seo\PsiSettings;
use Tests\TestCase;

/**
 * seo:credentials-import-from-env — the one-off migration off env vars and
 * onto encrypted platform_settings for Bing/Clarity/PageSpeed/DataForSEO
 * (CLAUDE.md's "credentials must leave the environment" rule).
 */
class SeoCredentialsImportFromEnvTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.bing.webmaster_api_key' => null,
            'services.microsoft.clarity.project_id' => null,
            'services.microsoft.clarity.api_token' => null,
            'services.google.pagespeed.api_key' => null,
            'services.dataforseo.login' => null,
            'services.dataforseo.password' => null,
        ]);
    }

    public function test_env_values_become_stored_settings_and_source_flips(): void
    {
        config([
            'services.bing.webmaster_api_key' => 'env-bing-key',
            'services.microsoft.clarity.project_id' => 'env-clarity-project',
            'services.microsoft.clarity.api_token' => 'env-clarity-token',
            'services.google.pagespeed.api_key' => 'env-psi-key',
            'services.dataforseo.login' => 'env-dfs-login',
            'services.dataforseo.password' => 'env-dfs-password',
        ]);

        $this->assertSame('env', app(BingSettings::class)->source());
        $this->assertSame('env', app(ClaritySettings::class)->source());
        $this->assertSame('env', app(PsiSettings::class)->source());
        $this->assertSame('env', app(DataForSeoSettings::class)->source());

        $this->artisan('seo:credentials-import-from-env')->assertExitCode(0);

        $this->assertSame('env-bing-key', PlatformSetting::get(BingSettings::SETTING_API_KEY));
        $this->assertSame('env-clarity-project', PlatformSetting::get(ClaritySettings::SETTING_PROJECT_ID));
        $this->assertSame('env-clarity-token', PlatformSetting::get(ClaritySettings::SETTING_API_TOKEN));
        $this->assertSame('env-psi-key', PlatformSetting::get(PsiSettings::SETTING_API_KEY));
        $this->assertSame('env-dfs-login', PlatformSetting::get(DataForSeoSettings::SETTING_LOGIN));
        $this->assertSame('env-dfs-password', PlatformSetting::get(DataForSeoSettings::SETTING_PASSWORD));

        // Bing/Clarity/PageSpeed now read as admin-stored; DataForSEO is
        // never typed at this site's own /admin, so a stored value reports
        // as 'platform' (see DataForSeoSettings), not the generic 'admin'.
        $this->assertSame('admin', app(BingSettings::class)->source());
        $this->assertSame('admin', app(ClaritySettings::class)->source());
        $this->assertSame('admin', app(PsiSettings::class)->source());
        $this->assertSame('platform', app(DataForSeoSettings::class)->source());
    }

    public function test_the_command_never_prints_a_credential_value(): void
    {
        config(['services.bing.webmaster_api_key' => 'super-secret-bing-key']);

        $this->artisan('seo:credentials-import-from-env')
            ->expectsOutputToContain('Bing API key')
            ->assertExitCode(0);

        $output = \Artisan::output();
        $this->assertStringNotContainsString('super-secret-bing-key', $output);
    }

    public function test_an_already_stored_value_is_left_alone_and_reported_separately(): void
    {
        PlatformSetting::put(BingSettings::SETTING_API_KEY, 'already-in-admin');
        config(['services.bing.webmaster_api_key' => 'env-would-be-ignored']);

        $this->artisan('seo:credentials-import-from-env')
            ->expectsOutputToContain('Already stored: Bing API key')
            ->assertExitCode(0);

        $this->assertSame('already-in-admin', PlatformSetting::get(BingSettings::SETTING_API_KEY));
    }

    public function test_a_blank_env_value_is_skipped_and_reported_as_absent(): void
    {
        config(['services.bing.webmaster_api_key' => '   ']);

        $this->artisan('seo:credentials-import-from-env')
            ->expectsOutputToContain('Absent from env and storage: Bing API key')
            ->assertExitCode(0);

        $this->assertNull(PlatformSetting::get(BingSettings::SETTING_API_KEY));
    }

    public function test_dry_run_writes_nothing(): void
    {
        config(['services.bing.webmaster_api_key' => 'env-bing-key']);

        $this->artisan('seo:credentials-import-from-env --dry-run')
            ->expectsOutputToContain('Would import: Bing API key')
            ->assertExitCode(0);

        $this->assertNull(PlatformSetting::get(BingSettings::SETTING_API_KEY));
        $this->assertSame('env', app(BingSettings::class)->source());
    }
}
