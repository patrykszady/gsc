<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\PlatformSetting;
use App\Models\Site;
use App\Services\GoogleBusinessProfileService;
use App\Support\GoogleOAuthApp;
use Tests\TestCase;

/**
 * This site's own Google OAuth client, saved from the admin (the JSON
 * Google Cloud Console downloads, or the two values) and overlaid onto
 * the Business Profile and Search Console config — per site, never env.
 */
class PlatformsGoogleCredentialsTest extends TestCase
{
    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.business_profile.client_id' => null,
            'services.google.business_profile.client_secret' => null,
            'services.google.business_profile.refresh_token' => null,
            'services.google.search_console.client_id' => null,
            'services.google.search_console.client_secret' => null,
            'services.google.search_console.refresh_token' => null,
        ]);
    }

    private function clientJson(): string
    {
        return json_encode(['web' => [
            'client_id' => '1234567890-abcdefghijklmnop.apps.googleusercontent.com',
            'project_id' => 'gs-construction',
            'client_secret' => 'GOCSPX-verysecret',
            'redirect_uris' => ['https://gs.construction/admin/platforms/gbp/callback'],
        ]]);
    }

    public function test_status_says_google_sign_in_is_not_set_up_and_lists_the_redirect_urls(): void
    {
        $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk()
            ->assertJsonPath('data.google.configured', false)
            ->assertJsonPath('data.google.source', null)
            ->assertJsonPath('data.google.redirect_uris.gbp', route('admin.platforms.gbp-callback', ['site' => Site::current()->primary_host]))
            ->assertJsonPath('data.google.redirect_uris.gsc', route('admin.platforms.gsc-callback', ['site' => Site::current()->primary_host]))
            ->assertJsonPath('data.gbp.app_credentials_configured', false)
            ->assertJsonPath('data.gsc.app_credentials_configured', false);
    }

    public function test_uploading_the_client_json_stores_it_encrypted_and_both_google_services_use_it(): void
    {
        $this->postJson('/api/admin/v1/platforms/google/credentials', ['client_json' => $this->clientJson()], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.google.configured', true)
            ->assertJsonPath('data.google.source', 'admin')
            ->assertJsonPath('data.google.project_id', 'gs-construction')
            ->assertJsonPath('data.gbp.app_credentials_configured', true)
            ->assertJsonPath('data.gbp.client_id_configured', true)
            ->assertJsonPath('data.gsc.app_credentials_configured', true)
            ->assertJsonMissing(['client_secret' => 'GOCSPX-verysecret']);

        $hint = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->json('data.google.client_id_hint');
        $this->assertStringStartsWith('1234567890-abc', $hint);
        $this->assertStringNotContainsString('GOCSPX', json_encode($this->getJson('/api/admin/v1/platforms/status', $this->bearer())->json()));

        // Encrypted at rest: the raw column is not the value.
        $raw = \DB::table('platform_settings')->where('key', GoogleOAuthApp::SETTING_CLIENT_SECRET)->value('value');
        $this->assertNotSame('GOCSPX-verysecret', $raw);
        $this->assertSame('GOCSPX-verysecret', PlatformSetting::get(GoogleOAuthApp::SETTING_CLIENT_SECRET));

        // A fresh request boots with the stored client in config, so the sign-in URL carries it.
        $url = $this->getJson('/api/admin/v1/platforms/gbp/oauth-url', $this->bearer())->assertOk()->json('data.url');
        $this->assertStringContainsString('client_id=1234567890-abcdefghijklmnop.apps.googleusercontent.com', $url);
        $this->assertStringContainsString(urlencode(route('admin.platforms.gbp-callback', ['site' => Site::current()->primary_host])), $url);
        $this->assertStringContainsString('client_id=1234567890-abcdefghijklmnop', $this->getJson('/api/admin/v1/platforms/gsc/oauth-url', $this->bearer())->json('data.url'));
    }

    public function test_the_two_values_can_be_typed_instead(): void
    {
        $this->postJson('/api/admin/v1/platforms/google/credentials', ['client_id' => ' abc.apps.googleusercontent.com ', 'client_secret' => 'GOCSPX-typed'], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.google.source', 'admin');

        $this->assertSame('abc.apps.googleusercontent.com', PlatformSetting::get(GoogleOAuthApp::SETTING_CLIENT_ID));
        $this->assertSame('GOCSPX-typed', PlatformSetting::get(GoogleOAuthApp::SETTING_CLIENT_SECRET));
    }

    public function test_a_file_that_is_not_an_oauth_client_is_refused_and_so_is_an_empty_form(): void
    {
        $this->postJson('/api/admin/v1/platforms/google/credentials', ['client_json' => '{"hello":"world"}'], $this->bearer())
            ->assertStatus(422)->assertJsonValidationErrors(['client_json']);
        $this->postJson('/api/admin/v1/platforms/google/credentials', ['client_json' => 'not json'], $this->bearer())
            ->assertStatus(422)->assertJsonValidationErrors(['client_json']);
        $this->postJson('/api/admin/v1/platforms/google/credentials', ['client_id' => 'only-an-id'], $this->bearer())
            ->assertStatus(422)->assertJsonValidationErrors(['client_id']);

        $this->assertNull(PlatformSetting::get(GoogleOAuthApp::SETTING_CLIENT_ID));
    }

    public function test_an_installed_type_client_file_is_accepted_too(): void
    {
        $json = json_encode(['installed' => ['client_id' => 'desk.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-desk']]);

        $this->postJson('/api/admin/v1/platforms/google/credentials', ['client_json' => $json], $this->bearer())
            ->assertOk()->assertJsonPath('data.google.configured', true);
    }

    public function test_removing_the_client_falls_back_to_env_or_nothing(): void
    {
        GoogleOAuthApp::save('stored.apps.googleusercontent.com', 'GOCSPX-stored');
        $this->assertSame('stored.apps.googleusercontent.com', config('services.google.business_profile.client_id'));

        $this->deleteJson('/api/admin/v1/platforms/google/credentials', [], $this->bearer())
            ->assertOk();

        $this->assertNotSame('admin', $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->json('data.google.source'));
        $this->assertNotSame('stored.apps.googleusercontent.com', config('services.google.business_profile.client_id'));
        $this->assertNull(PlatformSetting::get(GoogleOAuthApp::SETTING_CLIENT_ID));
        $this->assertNull(PlatformSetting::get(GoogleOAuthApp::SETTING_CLIENT_SECRET));
    }

    public function test_env_credentials_still_count_when_nothing_is_stored(): void
    {
        config(['services.google.business_profile.client_id' => 'env.apps.googleusercontent.com', 'services.google.business_profile.client_secret' => 'GOCSPX-env']);

        $status = GoogleOAuthApp::status();

        $this->assertTrue($status['configured']);
        $this->assertSame('env', $status['source']);
        $this->assertFalse(app(GoogleBusinessProfileService::class)->hasRefreshToken());
    }
}
