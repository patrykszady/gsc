<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\OAuthToken;
use App\Models\PlatformSetting;
use App\Models\ReviewUrl;
use App\Models\Testimonial;
use App\Services\YelpBusinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * /api/admin/v1/platforms — the ss-systems central admin's read model +
 * OAuth kickoff/teardown for the ported Platforms screen.
 *
 * Never exercises a real OAuth dance or a real provider API: status is
 * asserted straight off oauth_tokens rows this test creates itself, and
 * disconnect is asserted by checking the row is gone, not by trusting a
 * flash message.
 */
class PlatformsControllerTest extends TestCase
{
    /**
     * This is a real dev box (see the class docblock) whose .env carries
     * real GBP/GSC/Meta credentials as .env-level fallbacks — exactly the
     * "connected via env, no DB row" path GoogleBusinessProfileService and
     * GoogleSearchConsoleService support. Null them out so "disconnected"
     * tests are deterministic regardless of what's configured on this box,
     * rather than depending on ambient environment state.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.business_profile.refresh_token' => null,
            'services.google.search_console.refresh_token' => null,
            'services.meta.page_access_token' => null,
        ]);
    }

    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    public function test_status_requires_a_bearer_token(): void
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        $this->getJson('/api/admin/v1/platforms/status')->assertUnauthorized();

        $this->getJson('/api/admin/v1/platforms/status', ['Authorization' => 'Bearer wrong'])
            ->assertUnauthorized();
    }

    public function test_status_reports_disconnected_providers_by_default(): void
    {
        $response = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())
            ->assertOk()
            ->json('data');

        $this->assertFalse($response['gbp']['connected']);
        $this->assertFalse($response['gsc']['connected']);
        $this->assertFalse($response['meta']['connected']);
        // Yelp's own dedicated test below covers session_authenticated /
        // session_dead — quickCheckSession() reads this real dev box's
        // actual puppeteer cookie file when no cache flag is set, so its
        // value here isn't something this test can predict.
    }

    public function test_status_reports_connected_providers_and_never_leaks_token_values(): void
    {
        OAuthToken::create([
            'provider' => 'google_business_profile',
            'access_token' => 'gbp-secret-access-token',
            'refresh_token' => 'gbp-secret-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'granted_by_email' => 'owner@example.com',
            'scopes' => ['https://www.googleapis.com/auth/business.manage'],
        ]);

        OAuthToken::create([
            'provider' => 'google_search_console',
            'access_token' => 'gsc-secret-access-token',
            'refresh_token' => 'gsc-secret-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/webmasters'],
        ]);

        OAuthToken::create([
            'provider' => 'meta',
            'access_token' => 'meta-secret-page-token',
            'refresh_token' => 'meta-secret-user-token',
            'granted_by_email' => 'Jane Owner',
            'scopes' => ['pages_show_list'],
            'metadata' => [
                'page_id' => '123',
                'page_name' => 'Test Page',
                'ig_id' => '456',
                'ig_username' => 'testpage',
            ],
        ]);

        $response = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk();

        // No raw token value anywhere in the payload, for any provider.
        $response->assertDontSee('gbp-secret-access-token', false);
        $response->assertDontSee('gbp-secret-refresh-token', false);
        $response->assertDontSee('gsc-secret-access-token', false);
        $response->assertDontSee('gsc-secret-refresh-token', false);
        $response->assertDontSee('meta-secret-page-token', false);
        $response->assertDontSee('meta-secret-user-token', false);

        $data = $response->json('data');

        $this->assertTrue($data['gbp']['connected']);
        $this->assertSame('oauth', $data['gbp']['source']);
        $this->assertSame('owner@example.com', $data['gbp']['email']);
        $this->assertArrayNotHasKey('access_token', $data['gbp']);
        $this->assertArrayNotHasKey('refresh_token', $data['gbp']);

        $this->assertTrue($data['gsc']['connected']);
        $this->assertTrue($data['gsc']['write_scope']);

        $this->assertTrue($data['meta']['connected']);
        $this->assertSame('Test Page', $data['meta']['page_name']);
        $this->assertSame('testpage', $data['meta']['instagram_username']);
        $this->assertArrayNotHasKey('access_token', $data['meta']);
        $this->assertArrayNotHasKey('refresh_token', $data['meta']);
    }

    public function test_status_reports_yelp_session_state_from_local_cache_only(): void
    {
        Cache::put('yelp.session_dead', ['at' => '2026-08-01T00:00:00Z', 'note' => 'DataDome blocked login'], now()->addDay());
        PlatformSetting::put(YelpBusinessService::SETTING_EMAIL, 'yelp@example.com');
        PlatformSetting::put(YelpBusinessService::SETTING_PASSWORD, 'super-secret-password');

        $response = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk();

        // The password must never appear anywhere in the response.
        $response->assertDontSee('super-secret-password', false);

        $yelp = $response->json('data.yelp');
        $this->assertTrue($yelp['credentials_configured']);
        $this->assertSame('yelp@example.com', $yelp['email']);
        $this->assertTrue($yelp['session_dead']);
        $this->assertSame('DataDome blocked login', $yelp['session_dead_note']);
        // No more legacy-admin deep link — the whole panel is ported into
        // this API + the central admin's screen now.
        $this->assertArrayNotHasKey('legacy_url', $yelp);
        $this->assertTrue($yelp['has_password']);
        $this->assertSame(strlen('super-secret-password'), $yelp['password_len']);
        $this->assertSame(substr(hash('sha256', 'super-secret-password'), 0, 6), $yelp['password_fingerprint']);
    }

    public function test_oauth_url_builds_the_admin_site_callback_redirect_uri(): void
    {
        foreach (['gbp' => 'gbp', 'gsc' => 'gsc', 'meta' => 'meta'] as $provider => $segment) {
            $url = $this->getJson("/api/admin/v1/platforms/{$provider}/oauth-url", $this->bearer())
                ->assertOk()
                ->json('data.url');

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            $this->assertStringContainsString(
                "/admin/gs.construction/platforms/{$segment}/callback",
                $query['redirect_uri'],
            );
            // Never the legacy path — that's the whole point of the port.
            $this->assertStringNotContainsString('/admin-legacy/', $query['redirect_uri']);
        }
    }

    public function test_oauth_url_rejects_an_unknown_provider(): void
    {
        $this->getJson('/api/admin/v1/platforms/yelp/oauth-url', $this->bearer())->assertNotFound();
        $this->getJson('/api/admin/v1/platforms/bogus/oauth-url', $this->bearer())->assertNotFound();
    }

    public function test_disconnect_removes_only_the_targeted_providers_token(): void
    {
        OAuthToken::create(['provider' => 'google_business_profile', 'refresh_token' => 'gbp-token']);
        OAuthToken::create(['provider' => 'google_search_console', 'refresh_token' => 'gsc-token']);

        $this->deleteJson('/api/admin/v1/platforms/gbp', [], $this->bearer())
            ->assertNoContent();

        $this->assertNull(OAuthToken::forProvider('google_business_profile'));
        $this->assertNotNull(OAuthToken::forProvider('google_search_console'));

        $status = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->json('data');
        $this->assertFalse($status['gbp']['connected']);
        $this->assertTrue($status['gsc']['connected']);
    }

    public function test_disconnect_rejects_yelp_since_it_has_no_oauth_token(): void
    {
        $this->deleteJson('/api/admin/v1/platforms/yelp', [], $this->bearer())->assertNotFound();
    }

    public function test_gbp_status_reports_configuration_checklist_as_presence_booleans_never_values(): void
    {
        config([
            'services.google.business_profile.enabled' => true,
            'services.google.business_profile.client_id' => 'gbp-client-id-value',
            'services.google.business_profile.client_secret' => 'gbp-client-secret-value',
            'services.google.business_profile.account_id' => 'gbp-account-id-value',
            'services.google.business_profile.location_id' => 'gbp-location-id-value',
            'services.google.business_profile.refresh_token' => 'gbp-env-refresh-token-value',
        ]);

        $response = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk();

        // Presence booleans only — none of the actual configured values may
        // ever appear in the response body.
        $response->assertDontSee('gbp-client-id-value', false);
        $response->assertDontSee('gbp-client-secret-value', false);
        $response->assertDontSee('gbp-account-id-value', false);
        $response->assertDontSee('gbp-location-id-value', false);
        $response->assertDontSee('gbp-env-refresh-token-value', false);

        $gbp = $response->json('data.gbp');
        $this->assertTrue($gbp['enabled']);
        $this->assertTrue($gbp['client_id_configured']);
        $this->assertTrue($gbp['client_secret_configured']);
        $this->assertTrue($gbp['account_id_configured']);
        $this->assertTrue($gbp['location_id_configured']);
        $this->assertTrue($gbp['refresh_token_present']);
    }

    public function test_gbp_status_configuration_checklist_is_false_when_unset(): void
    {
        // setUp() already nulls the refresh token for determinism; blank
        // out the rest of the GBP config here too.
        config([
            'services.google.business_profile.enabled' => false,
            'services.google.business_profile.client_id' => null,
            'services.google.business_profile.client_secret' => null,
            'services.google.business_profile.account_id' => null,
            'services.google.business_profile.location_id' => null,
        ]);

        $gbp = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())
            ->assertOk()
            ->json('data.gbp');

        $this->assertFalse($gbp['enabled']);
        $this->assertFalse($gbp['client_id_configured']);
        $this->assertFalse($gbp['client_secret_configured']);
        $this->assertFalse($gbp['account_id_configured']);
        $this->assertFalse($gbp['location_id_configured']);
        $this->assertFalse($gbp['refresh_token_present']);
    }

    public function test_yelp_reviews_summary_requires_a_bearer_token(): void
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        $this->getJson('/api/admin/v1/platforms/yelp/reviews-summary')->assertUnauthorized();
    }

    public function test_yelp_reviews_summary_counts_only_yelp_platform_reviews_and_reports_the_newest_date(): void
    {
        $yelpOld = Testimonial::create([
            'reviewer_name' => 'Old Yelper',
            'review_description' => 'Great work.',
            'review_date' => '2026-01-10',
        ]);
        ReviewUrl::create(['testimonial_id' => $yelpOld->id, 'platform' => 'yelp', 'url' => 'https://yelp.com/biz/x?hrid=1']);

        $yelpNew = Testimonial::create([
            'reviewer_name' => 'New Yelper',
            'review_description' => 'Loved it.',
            'review_date' => '2026-07-15',
        ]);
        ReviewUrl::create(['testimonial_id' => $yelpNew->id, 'platform' => 'yelp', 'url' => 'https://yelp.com/biz/x?hrid=2']);

        $google = Testimonial::create([
            'reviewer_name' => 'Googler',
            'review_description' => 'Also great, but not Yelp.',
            'review_date' => '2026-08-01',
        ]);
        ReviewUrl::create(['testimonial_id' => $google->id, 'platform' => 'google', 'url' => 'https://g.page/r/x/review']);

        $data = $this->getJson('/api/admin/v1/platforms/yelp/reviews-summary', $this->bearer())
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['count']);
        $this->assertSame('2026-07-15', $data['latest_review_date']);
    }

    public function test_yelp_reviews_summary_reports_zero_and_null_with_no_yelp_reviews(): void
    {
        $data = $this->getJson('/api/admin/v1/platforms/yelp/reviews-summary', $this->bearer())
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $data['count']);
        $this->assertNull($data['latest_review_date']);
    }

    /**
     * Hits Google's Search Console API for real if it isn't mocked — this
     * test mocks the Artisan facade so seo:gsc-submit-sitemaps never
     * actually runs.
     */
    public function test_submit_gsc_sitemaps_never_executes_the_real_command(): void
    {
        Artisan::shouldReceive('call')->once()->with('seo:gsc-submit-sitemaps')->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn("  submitted https://gs.construction/sitemap.xml\n  submitted https://gs.construction/image-sitemap.xml\n");

        $data = $this->postJson('/api/admin/v1/platforms/gsc/submit-sitemaps', [], $this->bearer())
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString('submitted https://gs.construction/sitemap.xml', $data['output']);
    }

    public function test_submit_gsc_sitemaps_requires_a_bearer_token(): void
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        $this->postJson('/api/admin/v1/platforms/gsc/submit-sitemaps')->assertUnauthorized();
    }
}
