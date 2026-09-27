<?php

namespace Tests\Feature\Seo;

use App\Models\OAuthToken;
use App\Services\GoogleSearchConsoleService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Backport of jpeterson-design's describeFailure() (2026-09-27) — gsc's
 * submitSitemap()/listSites() used to inline a cruder status-code-only
 * message on a Google 403, which read identically whether the Search
 * Console API is switched off in the Cloud project behind the OAuth client
 * or the connected account simply does not own the property. The two are
 * only distinguishable from the response body.
 */
class GoogleSearchConsoleDescribeFailureTest extends TestCase
{
    private function connect(): void
    {
        config([
            'services.google.search_console.client_id' => 'client-id',
            'services.google.search_console.client_secret' => 'client-secret',
        ]);

        OAuthToken::create([
            'provider' => GoogleSearchConsoleService::PROVIDER,
            'refresh_token' => 'refresh-token',
            'access_token' => 'access-token',
            'access_token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/webmasters'],
        ]);
    }

    public function test_a_disabled_api_is_told_apart_from_an_unowned_property(): void
    {
        $this->connect();

        Http::fake([
            '*searchconsole.googleapis.com*' => Http::response(
                'Search Console API has not been used in project 650857697685 before or it is disabled.',
                403
            ),
        ]);

        $service = app(GoogleSearchConsoleService::class);
        $sites = $service->listSites();

        $this->assertNull($sites);

        $error = $service->getLastError();
        $this->assertSame('api_disabled', $error['reason']);
        $this->assertStringContainsString('650857697685', $error['message']);
        $this->assertStringContainsString('switched off', $error['message']);
    }

    public function test_an_unowned_property_is_told_apart_from_a_disabled_api(): void
    {
        $this->connect();

        Http::fake([
            '*searchconsole.googleapis.com*' => Http::response(
                ['error' => ['code' => 403, 'message' => "User does not have sufficient permission for site 'sc-domain:example.com'."]],
                403
            ),
        ]);

        $service = app(GoogleSearchConsoleService::class);
        $ok = $service->submitSitemap('sc-domain:example.com', 'https://example.com/sitemap.xml');

        $this->assertFalse($ok);

        $error = $service->getLastError();
        $this->assertSame('not_authorized', $error['reason']);
        $this->assertStringContainsString('sc-domain:example.com', $error['message']);
        $this->assertStringContainsString('must own that property', $error['message']);
    }
}
