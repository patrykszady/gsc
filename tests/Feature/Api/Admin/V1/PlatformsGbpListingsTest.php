<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\PlatformSetting;
use App\Models\Site;
use App\Services\GoogleBusinessProfileService;
use App\Support\GoogleBusinessListing;
use App\Support\Tenancy;
use Mockery\MockInterface;
use SsSystems\Platform\Google\BusinessProfile\Adapters\PlatformSettingListingStore;
use SsSystems\Platform\Google\BusinessProfile\Contracts\ListingStore;
use Tests\TestCase;

/**
 * Which Business Profile listings a site is shown.
 *
 * One Google account can manage several businesses. Patryk's manages both GS
 * Construction and J. Peterson Design, and the API hands every one of them to
 * whichever site holds the grant — so gs.construction's Platforms page listed
 * a client's business by name, under a heading inviting anyone to publish to
 * it. A listing belongs to the site whose host its own website points at.
 * (Since kit 0.14.0 the endpoint is the kit's ServesGbpPlatform; the host
 * filter is gs.construction's gbpSiteHosts() hook.)
 */
class PlatformsGbpListingsTest extends TestCase
{
    private const GS = 'locations/111';

    private const JPD = 'locations/222';

    /** What Google answers for the linked listing, when the test says so. */
    private array $location = ['name' => 'locations/111', 'metadata' => ['placeId' => 'ChIJtestplaceid']];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.admin_api.token' => 'test-admin-api-token']);

        $this->mock(GoogleBusinessProfileService::class, function (MockInterface $mock) {
            $mock->shouldReceive('grant')->andReturn(null);
            $mock->shouldReceive('hasRefreshToken')->andReturn(true);
            $mock->shouldReceive('hasBusinessScope')->andReturn(true);
            $mock->shouldReceive('getLastError')->andReturn(null);
            $mock->shouldReceive('grantStatus')->andReturn(['connected' => true, 'app_credentials_configured' => true]);
            $mock->shouldReceive('listAccounts')->andReturn([
                ['name' => 'accounts/900', 'accountName' => 'Patryk Szady'],
            ]);
            $mock->shouldReceive('getLocation')->with('111')->andReturnUsing(fn () => $this->location);
            $mock->shouldReceive('listLocations')->with('900')->andReturn([
                ['name' => self::GS, 'title' => 'GS Construction & Remodeling', 'websiteUri' => 'https://gs.construction/',
                    'metadata' => ['mapsUri' => 'https://maps.google.com/?cid=111', 'placeId' => 'ChIJgsconstruction']],
                ['name' => self::JPD, 'title' => 'J. Peterson Design, LLC', 'websiteUri' => 'https://www.jpeterson-design.com'],
                ['name' => 'locations/333', 'title' => 'A business with no website'],
            ]);
        });
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    public function test_a_site_is_shown_only_the_listings_whose_website_is_its_own(): void
    {
        $data = $this->getJson('/api/admin/v1/platforms/gbp/listings', $this->headers())
            ->assertOk()
            ->json('data');

        $titles = collect($data['accounts'])->flatMap(fn (array $a) => array_column($a['locations'], 'title'))->all();

        $this->assertSame(['GS Construction & Remodeling'], $titles);
        // Each listing carries its public Maps link and place id (2026-09-22),
        // which the central admin fills the market's Google URL from.
        $this->assertSame('https://maps.google.com/?cid=111', $data['accounts'][0]['locations'][0]['maps_url']);
        $this->assertSame('ChIJgsconstruction', $data['accounts'][0]['locations'][0]['place_id']);
        $this->assertTrue($data['filtered']);
        $this->assertSame(2, $data['hidden_count'], 'the client and the website-less listing are not this site\'s');
        $this->assertContains('gs.construction', $data['site_hosts']);
    }

    public function test_the_whole_account_is_available_when_it_is_asked_for(): void
    {
        $data = $this->getJson('/api/admin/v1/platforms/gbp/listings?all=1', $this->headers())
            ->assertOk()
            ->json('data');

        $titles = collect($data['accounts'])->flatMap(fn (array $a) => array_column($a['locations'], 'title'))->all();

        $this->assertCount(3, $titles, 'nothing is hidden from an operator who asks');
        $this->assertFalse($data['filtered']);
    }

    public function test_the_listing_already_linked_is_always_shown_even_if_it_does_not_match(): void
    {
        // A misconfiguration must stay visible, or nobody can undo it.
        PlatformSetting::put(PlatformSettingListingStore::LOCATION_ID, '222');

        $titles = collect($this->getJson('/api/admin/v1/platforms/gbp/listings', $this->headers())
            ->assertOk()
            ->json('data.accounts'))
            ->flatMap(fn (array $a) => array_column($a['locations'], 'title'))
            ->all();

        $this->assertContains('J. Peterson Design, LLC', $titles);
    }

    public function test_linking_a_listing_records_its_place_id_and_fills_the_google_link(): void
    {
        // A site with no Google link yet: the shared config builds one from
        // the default site's env place id, which is what "already has one"
        // means for gs.construction.
        config(['socials.google.url' => '']);

        $this->postJson('/api/admin/v1/platforms/gbp/listing', [
            'account_id' => '900',
            'location_id' => '111',
        ], $this->headers())->assertOk();

        $this->assertSame('900', PlatformSetting::get(PlatformSettingListingStore::ACCOUNT_ID));
        $this->assertSame('111', PlatformSetting::get(PlatformSettingListingStore::LOCATION_ID));
        $this->assertSame('ChIJtestplaceid', PlatformSetting::get(PlatformSettingListingStore::PLACE_ID));
        $this->assertSame(
            'https://www.google.com/maps/place/?q=place_id:ChIJtestplaceid',
            PlatformSetting::get(GoogleBusinessListing::SOCIAL_URL_SETTING),
            'the Social Media page gets the listing address without anyone pasting it',
        );
    }

    public function test_googles_own_maps_link_for_the_listing_is_kept_and_preferred(): void
    {
        // The kit asks Google once for the listing's public links (0.14.0):
        // its own Maps page wins over one built from the place id.
        config(['socials.google.url' => '']);
        $this->location = ['name' => 'locations/111', 'metadata' => [
            'placeId' => 'ChIJtestplaceid',
            'mapsUri' => 'https://maps.google.com/maps?cid=12345',
            'newReviewUri' => 'https://search.google.com/local/writereview?placeid=ChIJtestplaceid',
        ]];

        $this->postJson('/api/admin/v1/platforms/gbp/listing', ['account_id' => '900', 'location_id' => '111'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.maps_url', 'https://maps.google.com/maps?cid=12345');

        $this->assertSame('https://maps.google.com/maps?cid=12345', PlatformSetting::get(GoogleBusinessListing::SOCIAL_URL_SETTING));
    }

    public function test_a_google_link_already_on_file_is_left_alone(): void
    {
        config(['socials.google.url' => '']);
        PlatformSetting::put(GoogleBusinessListing::SOCIAL_URL_SETTING, 'https://g.page/gs-construction');

        $this->postJson('/api/admin/v1/platforms/gbp/listing', [
            'account_id' => '900',
            'location_id' => '111',
        ], $this->headers())->assertOk();

        $this->assertSame('https://g.page/gs-construction', PlatformSetting::get(GoogleBusinessListing::SOCIAL_URL_SETTING));
    }

    public function test_another_tenant_never_inherits_the_default_sites_place_id(): void
    {
        config(['services.google.business_profile.place_id' => 'ChIJgsconstruction']);

        $this->assertSame(
            'https://www.google.com/maps/place/?q=place_id:ChIJgsconstruction',
            Tenancy::for(Site::query()->where('slug', 'gsc')->firstOrFail(), fn () => app(ListingStore::class)->mapsUrl()),
        );

        Tenancy::for(Site::query()->where('slug', 'jpeterson')->firstOrFail(), function (): void {
            $this->assertNull(
                app(ListingStore::class)->mapsUrl(),
                'the env place id is gs.construction\'s — hers is empty until she links her own listing',
            );
        });
    }
}
