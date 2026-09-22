<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\PlatformSetting;
use App\Support\Seo\BingSettings;
use Tests\TestCase;

/**
 * Bing Webmaster Tools' per-site API key, saved from /admin into encrypted
 * platform_settings — same round trip as PlatformsGoogleCredentialsTest,
 * cloned for a single-field credential.
 */
class PlatformsBingCredentialsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bing.webmaster_api_key' => null]);
    }

    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    public function test_save_persists_the_api_key_encrypted(): void
    {
        $this->postJson('/api/admin/v1/platforms/bing/credentials', ['api_key' => 'bing-secret-key'], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.bing.configured', true)
            ->assertJsonPath('data.bing.source', 'admin');

        $this->assertSame('bing-secret-key', PlatformSetting::get(BingSettings::SETTING_API_KEY));

        $raw = \DB::table('platform_settings')->where('key', BingSettings::SETTING_API_KEY)->value('value');
        $this->assertNotSame('bing-secret-key', $raw);
    }

    public function test_validation_errors_map_to_the_field(): void
    {
        $this->postJson('/api/admin/v1/platforms/bing/credentials', ['api_key' => str_repeat('x', 256)], $this->bearer())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['api_key']);

        $this->assertNull(PlatformSetting::get(BingSettings::SETTING_API_KEY));
    }

    public function test_status_never_returns_the_raw_key(): void
    {
        $response = $this->postJson('/api/admin/v1/platforms/bing/credentials', ['api_key' => 'do-not-leak-this'], $this->bearer())
            ->assertOk();

        $response->assertDontSee('do-not-leak-this', false);
        $this->assertArrayNotHasKey('api_key', $response->json('data.bing'));

        $status = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk();
        $status->assertDontSee('do-not-leak-this', false);
        $this->assertSame(substr(hash('sha256', 'do-not-leak-this'), 0, 6), $status->json('data.bing.api_key_fingerprint'));
    }

    public function test_a_blank_resave_keeps_the_existing_key(): void
    {
        PlatformSetting::put(BingSettings::SETTING_API_KEY, 'already-saved');

        $this->postJson('/api/admin/v1/platforms/bing/credentials', ['api_key' => ''], $this->bearer())
            ->assertOk();

        $this->assertSame('already-saved', PlatformSetting::get(BingSettings::SETTING_API_KEY));
    }

    public function test_clearing_removes_the_stored_key(): void
    {
        PlatformSetting::put(BingSettings::SETTING_API_KEY, 'to-be-cleared');

        $this->deleteJson('/api/admin/v1/platforms/bing/credentials', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.bing.configured', false);

        $this->assertNull(PlatformSetting::get(BingSettings::SETTING_API_KEY));
    }

    public function test_env_key_still_counts_when_nothing_is_stored(): void
    {
        config(['services.bing.webmaster_api_key' => 'env-bing-key']);

        $data = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk()->json('data.bing');

        $this->assertTrue($data['configured']);
        $this->assertSame('env', $data['source']);
    }
}
