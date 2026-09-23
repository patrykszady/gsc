<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\PlatformSetting;
use App\Support\Seo\ClaritySettings;
use Tests\TestCase;

/**
 * Microsoft Clarity's per-site project id + API token, saved from /admin
 * into encrypted platform_settings.
 */
class PlatformsClarityCredentialsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.microsoft.clarity.project_id' => null,
            'services.microsoft.clarity.api_token' => null,
        ]);
    }

    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    public function test_save_persists_both_fields_encrypted(): void
    {
        $this->postJson('/api/admin/v1/platforms/clarity/credentials', [
            'project_id' => 'clarity-project-id',
            'api_token' => 'clarity-secret-token',
        ], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.clarity.configured', true)
            ->assertJsonPath('data.clarity.source', 'admin');

        $this->assertSame('clarity-project-id', PlatformSetting::get(ClaritySettings::SETTING_PROJECT_ID));
        $this->assertSame('clarity-secret-token', PlatformSetting::get(ClaritySettings::SETTING_API_TOKEN));

        $raw = \DB::table('platform_settings')->where('key', ClaritySettings::SETTING_API_TOKEN)->value('value');
        $this->assertNotSame('clarity-secret-token', $raw);
    }

    public function test_validation_errors_map_to_fields(): void
    {
        $this->postJson('/api/admin/v1/platforms/clarity/credentials', [
            'project_id' => str_repeat('x', 256),
            'api_token' => str_repeat('y', 5000),
        ], $this->bearer())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id', 'api_token']);

        $this->assertNull(PlatformSetting::get(ClaritySettings::SETTING_PROJECT_ID));
    }

    public function test_status_never_returns_the_raw_token(): void
    {
        $response = $this->postJson('/api/admin/v1/platforms/clarity/credentials', [
            'project_id' => 'proj-1',
            'api_token' => 'do-not-leak-this-token',
        ], $this->bearer())->assertOk();

        $response->assertDontSee('do-not-leak-this-token', false);
        $this->assertArrayNotHasKey('api_token', $response->json('data.clarity'));
    }

    public function test_a_blank_resave_keeps_the_existing_values(): void
    {
        PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, 'already-saved-project');
        PlatformSetting::put(ClaritySettings::SETTING_API_TOKEN, 'already-saved-token');

        $this->postJson('/api/admin/v1/platforms/clarity/credentials', [
            'project_id' => '',
            'api_token' => '',
        ], $this->bearer())->assertOk();

        $this->assertSame('already-saved-project', PlatformSetting::get(ClaritySettings::SETTING_PROJECT_ID));
        $this->assertSame('already-saved-token', PlatformSetting::get(ClaritySettings::SETTING_API_TOKEN));
    }

    public function test_clearing_removes_both_stored_fields(): void
    {
        PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, 'proj');
        PlatformSetting::put(ClaritySettings::SETTING_API_TOKEN, 'token');

        $this->deleteJson('/api/admin/v1/platforms/clarity/credentials', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.clarity.configured', false);

        $this->assertNull(PlatformSetting::get(ClaritySettings::SETTING_PROJECT_ID));
        $this->assertNull(PlatformSetting::get(ClaritySettings::SETTING_API_TOKEN));
    }

    public function test_source_stays_env_until_both_fields_are_admin_stored(): void
    {
        config([
            'services.microsoft.clarity.project_id' => 'env-project',
            'services.microsoft.clarity.api_token' => 'env-token',
        ]);

        // Only one field stored so far — GoogleOAuthApp::source()'s
        // precedent requires BOTH before calling the whole block 'admin'.
        PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, 'partial');

        $data = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk()->json('data.clarity');
        $this->assertSame('env', $data['source']);

        PlatformSetting::put(ClaritySettings::SETTING_API_TOKEN, 'now-complete');

        $data = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk()->json('data.clarity');
        $this->assertSame('admin', $data['source']);
    }

    /**
     * Clarity's API token is a JWT of ~700 characters; a 255 cap refused
     * every real one (2026-09-23).
     */
    public function test_a_real_length_clarity_token_is_accepted_and_stored(): void
    {
        $token = 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.'.str_repeat('a', 640).'.'.str_repeat('b', 340);
        $this->assertGreaterThan(900, strlen($token));

        $this->postJson('/api/admin/v1/platforms/clarity/credentials', [
            'project_id' => 'abcdefghij',
            'api_token' => $token,
        ], $this->bearer())->assertOk();

        $this->assertSame($token, app(\App\Support\Seo\ClaritySettings::class)->apiToken());
    }
}
