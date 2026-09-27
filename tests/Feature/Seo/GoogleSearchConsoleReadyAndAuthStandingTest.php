<?php

namespace Tests\Feature\Seo;

use App\Models\OAuthToken;
use App\Services\GoogleSearchConsoleService;
use Tests\TestCase;

/**
 * SearchConsoleClient's isReady()/isAuthStandingCondition() hooks — added to
 * the contract by kit/search-console-contract (SitemapSubmitter and the
 * UrlInspectionSweep both ask isReady() before spending anything; the
 * kit's SitemapSubmitter asks isAuthStandingCondition() on every failure).
 * Both are ported verbatim from what this site's own
 * SearchConsoleUrlInspector adapter and seo:gsc-submit-sitemaps command
 * already did inline — these tests pin that the extraction changed
 * nothing.
 */
class GoogleSearchConsoleReadyAndAuthStandingTest extends TestCase
{
    public function test_not_ready_without_a_stored_refresh_token(): void
    {
        $service = app(GoogleSearchConsoleService::class);

        $this->assertFalse($service->isReady());
    }

    public function test_ready_once_a_grant_with_a_refresh_token_is_stored(): void
    {
        OAuthToken::create([
            'provider' => GoogleSearchConsoleService::PROVIDER,
            'refresh_token' => 'r',
            'access_token' => 'a',
            'access_token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/webmasters'],
        ]);

        $service = app(GoogleSearchConsoleService::class);

        $this->assertTrue($service->isReady());
    }

    public function test_401_and_403_are_standing_conditions(): void
    {
        $service = app(GoogleSearchConsoleService::class);

        $this->assertTrue($service->isAuthStandingCondition(['status' => 401, 'message' => 'x']));
        $this->assertTrue($service->isAuthStandingCondition(['status' => 403, 'message' => 'x']));
    }

    public function test_the_statusless_no_access_token_message_is_a_standing_condition(): void
    {
        $service = app(GoogleSearchConsoleService::class);

        $this->assertTrue($service->isAuthStandingCondition(['message' => 'No access token — run search-console:auth']));
    }

    public function test_a_404_or_5xx_is_not_a_standing_condition(): void
    {
        $service = app(GoogleSearchConsoleService::class);

        $this->assertFalse($service->isAuthStandingCondition(['status' => 404, 'message' => 'not found']));
        $this->assertFalse($service->isAuthStandingCondition(['status' => 500, 'message' => 'boom']));
        $this->assertFalse($service->isAuthStandingCondition(null));
    }
}
