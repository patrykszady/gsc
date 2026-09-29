<?php

namespace Tests\Feature;

use App\Models\OAuthToken;
use App\Models\Site;
use App\Services\GoogleBusinessProfileService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Google\BusinessProfile\Client as GbpClient;
use SsSystems\Platform\Google\BusinessProfile\Contracts\ListingStore;
use Tests\TestCase;

/**
 * Two tenants on one deployment, one Google Business Profile client (kit
 * 0.14.0). Nothing of one tenant's Google state may reach another:
 *
 * - gs.construction's server-held grant and listing (the
 *   GOOGLE_BUSINESS_PROFILE_* env values) are the default site's only — the
 *   old service fell back to both for every tenant, so another tenant with
 *   no grant of its own would have posted to GS's listing with GS's grant;
 * - each tenant's grant is its own oauth_tokens row (BelongsToSite);
 * - the access-token cache is keyed by each grant's own refresh token — the
 *   old service cached every tenant's token under one fixed key, so the
 *   second tenant in a process read the first one's token.
 */
class GbpTenancyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.oauth.client_id' => '31627704418-shared.apps.googleusercontent.com',
            'services.google.oauth.client_secret' => 'GOCSPX-shared',
            'services.google.business_profile.refresh_token' => 'gs-env-refresh-token',
            'services.google.business_profile.account_id' => '111',
            'services.google.business_profile.location_id' => '222',
            'services.google.business_profile.place_id' => 'ChIJgsconstruction',
        ]);
        Http::preventStrayRequests();
    }

    private function gsc(): Site
    {
        return Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();
    }

    private function other(): Site
    {
        return Site::where('slug', 'jpeterson')->firstOrFail();
    }

    public function test_the_env_grant_listing_and_place_id_are_the_default_sites_only(): void
    {
        Tenancy::for($this->gsc(), function () {
            $gbp = app(GoogleBusinessProfileService::class);

            $this->assertSame('gs-env-refresh-token', $gbp->getRefreshToken());
            $this->assertSame(['account_id' => '111', 'location_id' => '222', 'source' => 'env'], $gbp->listing()->selected());
            $this->assertSame('ChIJgsconstruction', app(ListingStore::class)->placeId());
            $this->assertTrue($gbp->isConfigured());
        });

        Tenancy::for($this->other(), function () {
            $gbp = app(GoogleBusinessProfileService::class);

            $this->assertNull($gbp->getRefreshToken(), "another tenant never inherits GS's server-held grant");
            $this->assertSame(['account_id' => null, 'location_id' => null, 'source' => null], $gbp->listing()->selected());
            $this->assertNull(app(ListingStore::class)->placeId(), "nor GS's place id (its /review link)");
            $this->assertFalse($gbp->isConfigured(), 'so nothing posts or uploads for it');
            $this->assertNull($gbp->createLocalPost('https://x.test/p.jpg', 'Summary', 'https://x.test/'));
        });

        Http::assertNothingSent();
    }

    public function test_the_kits_client_resolves_to_this_sites_service(): void
    {
        $this->assertInstanceOf(GoogleBusinessProfileService::class, app(GbpClient::class));
        $this->assertNotSame(app(GbpClient::class), app(GbpClient::class), 'bound per resolution, never a process-wide singleton');
    }

    public function test_each_tenant_reads_its_own_grant_and_its_own_cached_access_token(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => fn (Request $request) => Http::response([
            'access_token' => 'at-for-'.$request['refresh_token'],
            'expires_in' => 3600,
        ])]);

        Tenancy::for($this->gsc(), fn () => OAuthToken::create(['provider' => 'google_business_profile', 'refresh_token' => 'rt-gsc']));
        Tenancy::for($this->other(), fn () => OAuthToken::create(['provider' => 'google_business_profile', 'refresh_token' => 'rt-other']));

        $this->assertSame('at-for-rt-gsc', Tenancy::for($this->gsc(), fn () => app(GoogleBusinessProfileService::class)->getAuthorizedToken()));
        $this->assertSame('at-for-rt-other', Tenancy::for($this->other(), fn () => app(GoogleBusinessProfileService::class)->getAuthorizedToken()));
        $this->assertSame('at-for-rt-gsc', Tenancy::for($this->gsc(), fn () => app(GoogleBusinessProfileService::class)->getAuthorizedToken()));

        // One refresh per tenant, each with its own refresh token; the third
        // read is served from gsc's own cache entry.
        $this->assertSame(['rt-gsc', 'rt-other'], Http::recorded()->map(fn (array $pair) => $pair[0]['refresh_token'])->all());
    }

    public function test_a_rotated_refresh_token_is_kept_on_that_tenants_own_row(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'at-1',
            'expires_in' => 3600,
            'refresh_token' => 'rt-other-rotated',
        ])]);

        Tenancy::for($this->gsc(), fn () => OAuthToken::create(['provider' => 'google_business_profile', 'refresh_token' => 'rt-gsc']));
        Tenancy::for($this->other(), fn () => OAuthToken::create(['provider' => 'google_business_profile', 'refresh_token' => 'rt-other']));

        Tenancy::for($this->other(), fn () => app(GoogleBusinessProfileService::class)->getAuthorizedToken());

        $this->assertSame('rt-other-rotated', OAuthToken::forSite($this->other())->where('provider', 'google_business_profile')->first()->refresh_token);
        $this->assertSame('rt-gsc', OAuthToken::forSite($this->gsc())->where('provider', 'google_business_profile')->first()->refresh_token);
    }

    public function test_a_listing_one_tenant_links_is_its_own(): void
    {
        Tenancy::for($this->other(), fn () => app(ListingStore::class)->link('accounts/900', 'locations/333'));

        $this->assertSame(
            ['account_id' => '900', 'location_id' => '333', 'source' => 'admin'],
            Tenancy::for($this->other(), fn () => app(ListingStore::class)->selected()),
        );
        $this->assertSame(
            ['account_id' => '111', 'location_id' => '222', 'source' => 'env'],
            Tenancy::for($this->gsc(), fn () => app(ListingStore::class)->selected()),
        );
    }
}
