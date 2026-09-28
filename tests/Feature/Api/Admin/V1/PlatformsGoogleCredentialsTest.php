<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\PlatformSetting;
use App\Services\GoogleBusinessProfileService;
use Tests\TestCase;

/**
 * The Google sign-in client is the ONE shared client in server
 * configuration since kit 0.14.0 (GOOGLE_OAUTH_CLIENT_ID/_SECRET —
 * gs.construction's Cloud project 31627704418, the same on every tenant).
 * The admin shows it and never saves one: POST/DELETE
 * platforms/google/credentials are refusals, and a client once stored per
 * site in platform_settings (the old App\Support\GoogleOAuthApp overlay) is
 * no longer read by anything.
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
            'services.google.oauth.client_id' => null,
            'services.google.oauth.client_secret' => null,
            'services.google.business_profile.client_id' => null,
            'services.google.business_profile.client_secret' => null,
            'services.google.business_profile.refresh_token' => null,
            'services.google.search_console.client_id' => null,
            'services.google.search_console.client_secret' => null,
            'services.google.search_console.refresh_token' => null,
        ]);
    }

    private function sharedClient(): void
    {
        config([
            'services.google.oauth.client_id' => '31627704418-abcdefghijklmnop.apps.googleusercontent.com',
            'services.google.oauth.client_secret' => 'GOCSPX-shared',
        ]);
    }

    public function test_status_says_google_sign_in_is_not_set_up_and_lists_both_redirect_urls(): void
    {
        $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk()
            ->assertJsonPath('data.google.configured', false)
            ->assertJsonPath('data.google.source', null)
            ->assertJsonPath('data.google.shared', true)
            ->assertJsonPath('data.google.redirect_uris.gbp', route('admin-oauth.callback', ['provider' => 'gbp']))
            ->assertJsonPath('data.google.redirect_uris.gsc', route('admin-oauth.callback', ['provider' => 'gsc']))
            ->assertJsonPath('data.gbp.app_credentials_configured', false)
            ->assertJsonPath('data.gsc.app_credentials_configured', false);
    }

    public function test_the_shared_client_is_reported_from_the_server_configuration_and_its_secret_never_leaves(): void
    {
        $this->sharedClient();

        $json = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk()
            ->assertJsonPath('data.google.configured', true)
            ->assertJsonPath('data.google.source', 'env')
            ->assertJsonPath('data.google.legacy_config', false)
            ->assertJsonPath('data.google.project_id', '31627704418')
            ->assertJsonPath('data.gbp.app_credentials_configured', true)
            ->assertJsonPath('data.gbp.client_id_configured', true)
            ->json();

        $this->assertStringStartsWith('31627704418-ab', $json['data']['google']['client_id_hint']);
        $this->assertStringNotContainsString('GOCSPX', json_encode($json));

        $url = $this->getJson('/api/admin/v1/platforms/gbp/oauth-url', $this->bearer())->assertOk()->json('data.url');
        $this->assertStringContainsString('client_id=31627704418-abcdefghijklmnop.apps.googleusercontent.com', $url);
        $this->assertStringContainsString(urlencode(route('admin-oauth.callback', ['provider' => 'gbp'])), $url);
        $this->assertStringContainsString('scope='.urlencode('https://www.googleapis.com/auth/business.manage openid email'), $url);
    }

    public function test_the_pre_014_business_profile_pair_is_still_read_and_says_so(): void
    {
        config([
            'services.google.business_profile.client_id' => '31627704418-legacy.apps.googleusercontent.com',
            'services.google.business_profile.client_secret' => 'GOCSPX-legacy',
        ]);

        $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk()
            ->assertJsonPath('data.google.configured', true)
            ->assertJsonPath('data.google.legacy_config', true);
    }

    public function test_saving_a_client_is_refused_and_nothing_is_stored(): void
    {
        $this->postJson('/api/admin/v1/platforms/google/credentials', [
            'client_id' => 'abc.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-typed',
        ], $this->bearer())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);

        $this->deleteJson('/api/admin/v1/platforms/google/credentials', [], $this->bearer())
            ->assertStatus(422)
            ->assertJsonMissingPath('errors');

        $this->assertNull(PlatformSetting::get('google.oauth.client_id'));
        $this->assertNull(PlatformSetting::get('google.oauth.client_secret'));
    }

    public function test_a_client_stored_per_site_before_014_is_ignored(): void
    {
        $this->sharedClient();
        PlatformSetting::put('google.oauth.client_id', 'stale-site-client.apps.googleusercontent.com');
        PlatformSetting::put('google.oauth.client_secret', 'GOCSPX-stale');

        $url = $this->getJson('/api/admin/v1/platforms/gbp/oauth-url', $this->bearer())->assertOk()->json('data.url');

        $this->assertStringContainsString('client_id=31627704418-abcdefghijklmnop.apps.googleusercontent.com', $url);
        $this->assertStringNotContainsString('stale-site-client', $url);
    }

    public function test_without_a_client_the_connect_button_gets_a_sentence_not_a_broken_google_link(): void
    {
        $this->getJson('/api/admin/v1/platforms/gbp/oauth-url', $this->bearer())->assertOk()
            ->assertJsonPath('data.url', null)
            ->assertJsonPath('data.message', 'Google sign-in is not set up on the server yet. This is on our side; nothing for you to do.');

        $this->assertFalse(app(GoogleBusinessProfileService::class)->hasRefreshToken());
    }

    public function test_search_console_signs_in_through_the_shared_client_once_it_is_set(): void
    {
        $env = [
            'GOOGLE_OAUTH_CLIENT_ID' => '31627704418-shared.apps.googleusercontent.com',
            'GOOGLE_OAUTH_CLIENT_SECRET' => 'GOCSPX-shared',
            'GOOGLE_SEARCH_CONSOLE_CLIENT_ID' => '31627704418-old.apps.googleusercontent.com',
            'GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET' => 'GOCSPX-old',
        ];
        $previous = array_map(fn (string $key) => $_SERVER[$key] ?? null, array_combine(array_keys($env), array_keys($env)));

        try {
            foreach ($env as $key => $value) {
                $_SERVER[$key] = $value;
            }
            $services = require config_path('services.php');
            $this->assertSame('31627704418-shared.apps.googleusercontent.com', $services['google']['search_console']['client_id']);
            $this->assertSame('GOCSPX-shared', $services['google']['search_console']['client_secret']);
            $this->assertSame('31627704418-shared.apps.googleusercontent.com', $services['google']['oauth']['client_id']);

            // Half a pair is no pair: Search Console keeps its old keys whole.
            $_SERVER['GOOGLE_OAUTH_CLIENT_SECRET'] = '';
            $services = require config_path('services.php');
            $this->assertSame('31627704418-old.apps.googleusercontent.com', $services['google']['search_console']['client_id']);
            $this->assertSame('GOCSPX-old', $services['google']['search_console']['client_secret']);
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
        }
    }
}
