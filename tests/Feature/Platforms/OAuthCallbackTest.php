<?php

namespace Tests\Feature\Platforms;

use App\Models\OAuthToken;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Auth\OAuthState;
use Tests\TestCase;

/**
 * routes/web.php's /admin-oauth/{provider}/callback — the session-less
 * callback shared with jpeterson-design, alongside the older
 * 'auth'-protected /admin/{site}/platforms/{provider}/callback routes.
 * See that route block's docblock and SsSystems\Platform\Auth\OAuthState.
 *
 * NEVER calls a real Google/Meta token endpoint — every test that reaches
 * exchangeCodeAndStore() goes through Http::fake().
 */
class OAuthCallbackTest extends TestCase
{
    public function test_callback_rejects_a_missing_state(): void
    {
        $response = $this->get('/admin-oauth/gbp/callback?code=abc123');

        $response->assertRedirect();
        $this->assertStringContainsString('/admin/gsc/platforms?error=', $response->headers->get('Location'));
        $this->assertStringContainsString('expired+or+was+invalid', $response->headers->get('Location'));
    }

    public function test_callback_rejects_a_state_minted_for_a_different_provider(): void
    {
        $state = OAuthState::make('meta');

        $response = $this->get('/admin-oauth/gbp/callback?code=abc123&state='.urlencode($state));

        $response->assertRedirect();
        $this->assertStringContainsString('error=', $response->headers->get('Location'));
    }

    public function test_callback_rejects_an_unknown_provider(): void
    {
        $this->get('/admin-oauth/not-a-provider/callback')->assertNotFound();
    }

    public function test_callback_redirects_with_an_error_when_the_provider_returns_no_code(): void
    {
        $state = OAuthState::make('meta');

        $response = $this->get('/admin-oauth/meta/callback?state='.urlencode($state).'&error=access_denied');

        $response->assertRedirect();
        $this->assertStringContainsString('/admin/gsc/platforms?error=', $response->headers->get('Location'));
    }

    public function test_callback_exchanges_a_valid_code_and_redirects_to_the_central_admin(): void
    {
        config([
            'services.google.business_profile.client_id' => 'test-client-id',
            'services.google.business_profile.client_secret' => 'test-client-secret',
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'refresh_token' => 'new-refresh-token',
                'access_token' => 'new-access-token',
                'expires_in' => 3600,
            ]),
            'www.googleapis.com/oauth2/v3/userinfo*' => Http::response(['email' => 'jenn@example.com']),
        ]);

        $state = OAuthState::make('gbp');

        $response = $this->get('/admin-oauth/gbp/callback?code=abc123&state='.urlencode($state));

        $response->assertRedirect('/admin/gsc/platforms?connected=gbp');
        $this->assertNotNull(OAuthToken::forProvider('google_business_profile'));
        $this->assertSame('new-refresh-token', OAuthToken::forProvider('google_business_profile')->refresh_token);

        // redirect_uri sent to Google must be this site's own callback route.
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['redirect_uri'] === route('admin-oauth.callback', ['provider' => 'gbp']));
    }

    public function test_callback_redirects_with_an_error_when_exchange_fails(): void
    {
        config([
            'services.google.business_profile.client_id' => 'test-client-id',
            'services.google.business_profile.client_secret' => 'test-client-secret',
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Bad code'], 400),
        ]);

        $state = OAuthState::make('gbp');

        $response = $this->get('/admin-oauth/gbp/callback?code=bad-code&state='.urlencode($state));

        $response->assertRedirect();
        $this->assertStringContainsString('/admin/gsc/platforms?error=', $response->headers->get('Location'));
        $this->assertNull(OAuthToken::forProvider('google_business_profile'));
    }

    /*
    | Kit 0.14.0: the callback is the kit's hardened Google\Http\OAuthCallback.
    */

    public function test_the_outcome_goes_to_this_tenants_own_platforms_screen(): void
    {
        // The key AdminProxyController forwards with — never a hard-coded /admin/gsc.
        config(['services.ss.site_key' => 'jpeterson']);
        $this->assertStringStartsWith('/admin/jpeterson/platforms?error=', $this->get('/admin-oauth/gbp/callback?code=abc123')->headers->get('Location'));

        // No key configured: the tenant's own slug.
        config(['services.ss.site_key' => null]);
        $this->assertStringStartsWith('/admin/gsc/platforms?error=', $this->get('/admin-oauth/gbp/callback?code=abc123')->headers->get('Location'));
    }

    public function test_a_business_profile_grant_records_the_shared_client_that_issued_it(): void
    {
        config([
            'services.google.oauth.client_id' => '31627704418-shared.apps.googleusercontent.com',
            'services.google.oauth.client_secret' => 'GOCSPX-shared',
        ]);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'refresh_token' => 'new-refresh-token',
                'access_token' => 'new-access-token',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/business.manage openid email',
            ]),
            'www.googleapis.com/oauth2/v3/userinfo*' => Http::response(['email' => 'owner@example.com']),
        ]);
        Http::preventStrayRequests();

        $this->get('/admin-oauth/gbp/callback?code=abc123&state='.urlencode(OAuthState::make('gbp')))
            ->assertRedirect('/admin/gsc/platforms?connected=gbp');

        $row = OAuthToken::forProvider('google_business_profile');
        $this->assertSame('31627704418-shared.apps.googleusercontent.com', $row->metadata['oauth_client_id']);
        $this->assertSame(['https://www.googleapis.com/auth/business.manage', 'openid', 'email'], $row->scopes);
        $this->assertSame('owner@example.com', $row->granted_by_email);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['client_id'] === '31627704418-shared.apps.googleusercontent.com');
    }

    public function test_search_console_still_connects_through_its_own_service_on_the_same_client(): void
    {
        config([
            'services.google.search_console.client_id' => '31627704418-shared.apps.googleusercontent.com',
            'services.google.search_console.client_secret' => 'GOCSPX-shared',
        ]);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'refresh_token' => 'gsc-refresh-token',
                'access_token' => 'gsc-access-token',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/webmasters',
            ]),
            'www.googleapis.com/oauth2/v3/userinfo*' => Http::response(['email' => 'owner@example.com']),
        ]);
        Http::preventStrayRequests();

        $this->get('/admin-oauth/gsc/callback?code=abc123&state='.urlencode(OAuthState::make('gsc')))
            ->assertRedirect('/admin/gsc/platforms?connected=gsc');

        $this->assertSame('gsc-refresh-token', OAuthToken::forProvider('google_search_console')->refresh_token);
        $this->assertNull(OAuthToken::forProvider('google_business_profile'));
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['client_id'] === '31627704418-shared.apps.googleusercontent.com'
            && $request['redirect_uri'] === route('admin-oauth.callback', ['provider' => 'gsc']));
    }

    public function test_a_provider_that_does_not_answer_is_a_calm_try_again(): void
    {
        config([
            'services.google.oauth.client_id' => '31627704418-shared.apps.googleusercontent.com',
            'services.google.oauth.client_secret' => 'GOCSPX-shared',
        ]);
        Http::fake(['oauth2.googleapis.com/*' => Http::failedConnection('timed out')]);
        Http::preventStrayRequests();

        $location = $this->get('/admin-oauth/gbp/callback?code=abc123&state='.urlencode(OAuthState::make('gbp')))
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith('/admin/gsc/platforms?error=', $location);
        $this->assertNull(OAuthToken::forProvider('google_business_profile'));
    }
}
