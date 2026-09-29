<?php

namespace Tests\Feature;

use App\Models\OAuthToken;
use App\Models\Site;
use App\Services\GoogleBusinessProfileService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A Google grant belongs to the OAuth client that issued it (a refresh
 * token refreshes nowhere else). Until kit 0.14.0 this was enforced when an
 * admin SAVED a different per-site client (App\Support\GoogleOAuthApp's
 * forgetGrantsFromThePreviousClient(), ported from jpeterson-design). The
 * client is shared server configuration now, so there is no save event:
 * the kit's Business Profile client records the issuing client on every
 * grant (`metadata.oauth_client_id`) and forgets a grant from any other
 * client on sight — or, for a pre-0.14 row with no record, the first time
 * Google answers `unauthorized_client` for it. The Platforms card then asks
 * for the reconnect that is required anyway instead of reading "Connected".
 *
 * gsc is multi-tenant: OAuthToken's BelongsToSite scope keeps every read and
 * delete to Site::current(), so forgetting one tenant's dead grant never
 * touches another's. Search Console grants are not the Business Profile
 * client's to judge and are left alone.
 */
class GoogleOAuthClientSwapTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const CLIENT = '31627704418-shared.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.oauth.client_id' => self::CLIENT,
            'services.google.oauth.client_secret' => 'GOCSPX-shared',
            'services.google.business_profile.refresh_token' => null,
        ]);
        Http::preventStrayRequests();
    }

    private function defaultSite(): Site
    {
        return Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();
    }

    private function grant(string $provider = 'google_business_profile', ?string $issuedBy = 'old-client.apps.googleusercontent.com'): OAuthToken
    {
        return OAuthToken::create([
            'provider' => $provider,
            'refresh_token' => 'issued-by-'.($issuedBy ?? 'nobody-knows'),
            'scopes' => ['https://www.googleapis.com/auth/business.manage'],
            'metadata' => $issuedBy !== null ? ['oauth_client_id' => $issuedBy] : null,
        ]);
    }

    public function test_a_grant_from_another_client_is_forgotten_on_sight_and_search_console_is_left_alone(): void
    {
        Site::setCurrent($this->defaultSite());
        $this->grant();
        $this->grant('google_search_console');

        $gbp = app(GoogleBusinessProfileService::class);

        $this->assertFalse($gbp->hasRefreshToken());
        $this->assertSame('client_changed', $gbp->getLastError()['reason']);
        $this->assertNull(OAuthToken::forProvider('google_business_profile'), 'a grant from the previous client must not survive');
        $this->assertNotNull(OAuthToken::forProvider('google_search_console'));
        Http::assertNothingSent();
    }

    public function test_a_grant_from_the_shared_client_is_kept(): void
    {
        Site::setCurrent($this->defaultSite());
        $this->grant(issuedBy: self::CLIENT);

        $this->assertTrue(app(GoogleBusinessProfileService::class)->hasRefreshToken());
        $this->assertNotNull(OAuthToken::forProvider('google_business_profile'));
    }

    public function test_a_pre_014_grant_is_kept_and_stamped_with_the_client_on_its_first_refresh(): void
    {
        Site::setCurrent($this->defaultSite());
        $this->grant(issuedBy: null);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-1', 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/business.manage'])]);

        $this->assertSame('at-1', app(GoogleBusinessProfileService::class)->getAuthorizedToken());

        $row = OAuthToken::forProvider('google_business_profile');
        $this->assertSame(self::CLIENT, $row->metadata['oauth_client_id']);
        $this->assertSame('at-1', $row->access_token, 'the refreshed access token is persisted to the row');
    }

    public function test_a_pre_014_grant_google_refuses_as_unauthorized_client_is_forgotten(): void
    {
        Site::setCurrent($this->defaultSite());
        $this->grant(issuedBy: null);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'unauthorized_client', 'error_description' => 'Unauthorized'], 401)]);

        $gbp = app(GoogleBusinessProfileService::class);

        $this->assertNull($gbp->getAuthorizedToken());
        $this->assertSame('client_changed', $gbp->getLastError()['reason']);
        $this->assertNull(OAuthToken::forProvider('google_business_profile'));
    }

    public function test_the_server_client_refused_keeps_the_grant(): void
    {
        Site::setCurrent($this->defaultSite());
        $this->grant(issuedBy: null);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_client', 'error_description' => 'The OAuth client was not found.'], 401)]);

        $gbp = app(GoogleBusinessProfileService::class);

        $this->assertNull($gbp->getAuthorizedToken());
        $this->assertSame('client_rejected', $gbp->getLastError()['reason']);
        $this->assertNotNull(OAuthToken::forProvider('google_business_profile'), 'our own client problem must never delete the owner\'s grant');
    }

    public function test_with_no_client_on_the_server_nothing_is_judged(): void
    {
        config([
            'services.google.oauth.client_id' => null,
            'services.google.oauth.client_secret' => null,
            'services.google.business_profile.client_id' => null,
            'services.google.business_profile.client_secret' => null,
            'services.google.search_console.client_id' => null,
            'services.google.search_console.client_secret' => null,
        ]);
        Site::setCurrent($this->defaultSite());
        $this->grant();

        $this->assertTrue(app(GoogleBusinessProfileService::class)->hasRefreshToken());
        $this->assertNotNull(OAuthToken::forProvider('google_business_profile'));
    }

    public function test_forgetting_one_sites_dead_grant_never_touches_another_sites_grant(): void
    {
        $gsc = $this->defaultSite();
        $other = Site::where('slug', '!=', $gsc->slug)->firstOrFail();

        Site::setCurrent($other);
        $this->grant(); // the other tenant's grant, from the previous client too

        Site::setCurrent($gsc);
        $this->grant();

        $this->assertFalse(app(GoogleBusinessProfileService::class)->hasRefreshToken());

        $this->assertSame(0, OAuthToken::forSite($gsc)->count(), "gsc's own dead grant is gone");
        $this->assertSame(1, OAuthToken::forSite($other)->count(), "the other tenant's grant is untouched until that tenant reads it");
    }
}
