<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\PlatformSetting;
use App\Support\Seo\PsiSettings;
use Tests\TestCase;

/**
 * PageSpeed Insights' optional per-site API key. Unlike the other three
 * sources this one never gates anything — PSI runs keyless on Google's
 * shared limit either way — so 'configured' here just mirrors
 * usingOwnKey(), a soft "using your own key" signal, never a
 * connected/disconnected boolean.
 */
class PlatformsPagespeedCredentialsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.pagespeed.api_key' => null]);
    }

    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    public function test_save_persists_the_api_key_encrypted(): void
    {
        $this->postJson('/api/admin/v1/platforms/pagespeed/credentials', ['api_key' => 'psi-secret-key'], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.pagespeed.using_own_key', true)
            ->assertJsonPath('data.pagespeed.source', 'admin');

        $this->assertSame('psi-secret-key', PlatformSetting::get(PsiSettings::SETTING_API_KEY));

        $raw = \DB::table('platform_settings')->where('key', PsiSettings::SETTING_API_KEY)->value('value');
        $this->assertNotSame('psi-secret-key', $raw);
    }

    public function test_validation_errors_map_to_the_field(): void
    {
        $this->postJson('/api/admin/v1/platforms/pagespeed/credentials', ['api_key' => str_repeat('x', 256)], $this->bearer())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['api_key']);

        $this->assertNull(PlatformSetting::get(PsiSettings::SETTING_API_KEY));
    }

    public function test_status_never_returns_the_raw_key(): void
    {
        $response = $this->postJson('/api/admin/v1/platforms/pagespeed/credentials', ['api_key' => 'do-not-leak-this'], $this->bearer())
            ->assertOk();

        $response->assertDontSee('do-not-leak-this', false);
        $this->assertArrayNotHasKey('api_key', $response->json('data.pagespeed'));
    }

    public function test_a_blank_resave_keeps_the_existing_key(): void
    {
        PlatformSetting::put(PsiSettings::SETTING_API_KEY, 'already-saved');

        $this->postJson('/api/admin/v1/platforms/pagespeed/credentials', ['api_key' => ''], $this->bearer())
            ->assertOk();

        $this->assertSame('already-saved', PlatformSetting::get(PsiSettings::SETTING_API_KEY));
    }

    public function test_clearing_falls_back_to_the_keyless_shared_limit_not_a_broken_state(): void
    {
        PlatformSetting::put(PsiSettings::SETTING_API_KEY, 'to-be-cleared');

        $this->deleteJson('/api/admin/v1/platforms/pagespeed/credentials', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.pagespeed.using_own_key', false);

        $this->assertNull(PlatformSetting::get(PsiSettings::SETTING_API_KEY));
    }
}
