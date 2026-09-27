<?php

namespace Tests\Feature\Social;

use App\Models\OAuthToken;
use App\Services\MetaSocialService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * gsc's own Meta OAuth scope set, asserted by CONTENT — not just "some
 * array of strings" — now that the scope list travels as a call-time
 * argument into the kit's `SsSystems\Platform\Social\MetaGraphClient`
 * (2026-09-27, kit 0.13.0 unit 9) rather than a hardcoded value inside it.
 * gsc/jpeterson-design request this exact 4-scope set (no Facebook Page
 * posting scopes — only Instagram is published to); hive.contractors
 * requests its OWN different 4; dawnsellshomes will request 6. Each site
 * gets its own version of this test — this one is gsc's.
 *
 * `MetaSocialService` does NOT re-declare `getOAuthUrl()`/
 * `exchangeCodeAndStore()` at a narrower arity (PHP's override-
 * compatibility rules refuse that, however many parent parameters carry
 * defaults — see `MetaGraphClient::getOAuthUrl()`'s docblock), so every
 * call site passes `MetaSocialService::OAUTH_SCOPES` explicitly. This test
 * guards both ends: the constant's own content, and that what a real call
 * site sends is genuinely that content, not some other list.
 */
class MetaOAuthScopesTest extends TestCase
{
    public function test_gscs_oauth_scope_constant_is_exactly_its_four_production_scopes(): void
    {
        $this->assertSame([
            'pages_show_list',
            'business_management',
            'instagram_basic',
            'instagram_content_publish',
        ], MetaSocialService::OAUTH_SCOPES);

        // Explicitly NOT a Facebook Page posting scope — gsc only ever
        // publishes to the linked Instagram Business account, and App
        // Review is not part of this site's Meta setup.
        $this->assertNotContains('pages_manage_posts', MetaSocialService::OAUTH_SCOPES);
        $this->assertNotContains('pages_read_engagement', MetaSocialService::OAUTH_SCOPES);
    }

    public function test_get_oauth_url_encodes_exactly_the_oauth_scopes_constant(): void
    {
        $url = app(MetaSocialService::class)->getOAuthUrl(
            'https://gs.construction/admin-oauth/meta/callback',
            MetaSocialService::OAUTH_SCOPES,
            'test-state',
        );

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame(
            implode(',', MetaSocialService::OAUTH_SCOPES),
            $query['scope'],
        );
    }

    public function test_exchange_code_and_store_records_exactly_the_oauth_scopes_constant(): void
    {
        config([
            'services.meta.app_id' => 'test-app-id',
            'services.meta.app_secret' => 'test-app-secret',
        ]);

        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::sequence()
                ->push(['access_token' => 'short-lived'])
                ->push(['access_token' => 'long-lived']),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
                ['id' => '123', 'name' => 'GSC Page', 'access_token' => 'page-token', 'instagram_business_account' => ['id' => '456', 'username' => 'gscpage']],
            ]]),
            'graph.facebook.com/*/me*' => Http::response(['id' => '999', 'name' => 'Owner', 'email' => 'owner@example.test']),
        ]);

        $result = app(MetaSocialService::class)->exchangeCodeAndStore(
            'the-code',
            'https://gs.construction/admin-oauth/meta/callback',
            MetaSocialService::OAUTH_SCOPES,
        );

        $this->assertTrue($result['success']);

        // `oauth_tokens.scopes` is where gsc/jpeterson's OAuthTokenCredentialStore
        // adapter keeps the granted list (see its class docblock) — the site's own
        // storage column, read back through the site's own model, never the kit.
        $this->assertSame(MetaSocialService::OAUTH_SCOPES, OAuthToken::forProvider('meta')->scopes);
    }
}
