<?php

namespace Tests\Feature\Platforms;

use App\Models\OAuthToken;
use App\Support\OAuthState;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * routes/web.php's /admin-oauth/{provider}/callback — the session-less
 * callback shared with jpeterson-design, alongside the older
 * 'auth'-protected /admin/{site}/platforms/{provider}/callback routes.
 * See that route block's docblock and App\Support\OAuthState.
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
}
