<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\PlatformSetting;
use App\Support\Seo\BingSettings;
use App\Support\Seo\ClaritySettings;
use App\Support\Seo\DataForSeoSettings;
use App\Support\Seo\PsiSettings;
use Tests\TestCase;

/**
 * POST platforms/seo-credentials/import — the central admin's API door onto
 * seo:credentials-import-from-env (App\Support\Seo\SeoCredentialsImport),
 * reached from the SEO screen's Connect Services modal so nobody has to ssh
 * in to run the command. Cloned from PlatformsBingCredentialsTest's shape.
 */
class PlatformsSeoCredentialsImportTest extends TestCase
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

    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    public function test_importing_one_source_leaves_the_others_env_sourced(): void
    {
        config([
            'services.bing.webmaster_api_key' => 'env-bing-key',
            'services.microsoft.clarity.project_id' => 'env-clarity-project',
            'services.microsoft.clarity.api_token' => 'env-clarity-token',
        ]);

        $response = $this->postJson('/api/admin/v1/platforms/seo-credentials/import', ['sources' => ['bing']], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.imported', ['Bing API key'])
            ->assertJsonPath('data.already_stored', [])
            ->assertJsonPath('data.status.bing.source', 'admin')
            ->assertJsonPath('data.status.clarity.source', 'env');

        $response->assertDontSee('env-bing-key', false);
        $response->assertDontSee('env-clarity-project', false);
        $response->assertDontSee('env-clarity-token', false);

        $this->assertSame('env-bing-key', PlatformSetting::get(BingSettings::SETTING_API_KEY));
        // Clarity was not in the requested sources — still env-sourced, not stored.
        $this->assertNull(PlatformSetting::get(ClaritySettings::SETTING_PROJECT_ID));
        $this->assertNull(PlatformSetting::get(ClaritySettings::SETTING_API_TOKEN));
    }

    public function test_importing_with_no_sources_imports_everything_present(): void
    {
        config([
            'services.bing.webmaster_api_key' => 'env-bing-key',
            'services.microsoft.clarity.project_id' => 'env-clarity-project',
            'services.microsoft.clarity.api_token' => 'env-clarity-token',
            'services.google.pagespeed.api_key' => 'env-psi-key',
            'services.dataforseo.login' => 'env-dfs-login',
            'services.dataforseo.password' => 'env-dfs-password',
        ]);

        $response = $this->postJson('/api/admin/v1/platforms/seo-credentials/import', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.imported', [
                'Bing API key',
                'Clarity project ID',
                'Clarity API token',
                'PageSpeed API key',
                'DataForSEO login',
                'DataForSEO password',
            ])
            ->assertJsonPath('data.status.bing.source', 'admin')
            ->assertJsonPath('data.status.clarity.source', 'admin')
            ->assertJsonPath('data.status.pagespeed.source', 'admin')
            ->assertJsonPath('data.status.dataforseo.source', 'platform');

        $response->assertDontSee('env-bing-key', false);
        $response->assertDontSee('env-clarity-project', false);
        $response->assertDontSee('env-clarity-token', false);
        $response->assertDontSee('env-psi-key', false);
        $response->assertDontSee('env-dfs-login', false);
        $response->assertDontSee('env-dfs-password', false);

        $this->assertSame('env-bing-key', PlatformSetting::get(BingSettings::SETTING_API_KEY));
        $this->assertSame('env-clarity-project', PlatformSetting::get(ClaritySettings::SETTING_PROJECT_ID));
        $this->assertSame('env-clarity-token', PlatformSetting::get(ClaritySettings::SETTING_API_TOKEN));
        $this->assertSame('env-psi-key', PlatformSetting::get(PsiSettings::SETTING_API_KEY));
        $this->assertSame('env-dfs-login', PlatformSetting::get(DataForSeoSettings::SETTING_LOGIN));
        $this->assertSame('env-dfs-password', PlatformSetting::get(DataForSeoSettings::SETTING_PASSWORD));
    }

    public function test_a_second_import_reports_already_stored(): void
    {
        config(['services.bing.webmaster_api_key' => 'env-bing-key']);

        $this->postJson('/api/admin/v1/platforms/seo-credentials/import', ['sources' => ['bing']], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.imported', ['Bing API key']);

        $this->postJson('/api/admin/v1/platforms/seo-credentials/import', ['sources' => ['bing']], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.imported', [])
            ->assertJsonPath('data.already_stored', ['Bing API key']);

        $this->assertSame('env-bing-key', PlatformSetting::get(BingSettings::SETTING_API_KEY));
    }

    public function test_an_invalid_source_is_rejected(): void
    {
        $this->postJson('/api/admin/v1/platforms/seo-credentials/import', ['sources' => ['not-a-source']], $this->bearer())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sources.0']);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->postJson('/api/admin/v1/platforms/seo-credentials/import', ['sources' => ['bing']])
            ->assertStatus(401);
    }
}
