<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\PlatformSetting;
use App\Services\GoogleBusinessProfileService;
use App\Support\GoogleBusinessListing;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Which Business Profile listings a site is shown.
 *
 * One Google account can manage several businesses. Patryk's manages both GS
 * Construction and J. Peterson Design, and the API hands every one of them to
 * whichever site holds the grant — so gs.construction's Platforms page listed
 * a client's business by name, under a heading inviting anyone to publish to
 * it. A listing belongs to the site whose host its own website points at.
 */
class PlatformsGbpListingsTest extends TestCase
{
    private const GS = 'locations/111';

    private const JPD = 'locations/222';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.admin_api.token' => 'test-admin-api-token']);

        $this->mock(GoogleBusinessProfileService::class, function (MockInterface $mock) {
            $mock->shouldReceive('hasRefreshToken')->andReturn(true);
            $mock->shouldReceive('hasBusinessScope')->andReturn(true);
            $mock->shouldReceive('getLastError')->andReturn(null);
            $mock->shouldReceive('listAccounts')->andReturn([
                ['name' => 'accounts/900', 'accountName' => 'Patryk Szady'],
            ]);
            $mock->shouldReceive('listLocations')->with('900')->andReturn([
                ['name' => self::GS, 'title' => 'GS Construction & Remodeling', 'websiteUri' => 'https://gs.construction/'],
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
        PlatformSetting::put(GoogleBusinessListing::SETTING_LOCATION_ID, '222');
        GoogleBusinessListing::apply();

        $titles = collect($this->getJson('/api/admin/v1/platforms/gbp/listings', $this->headers())
            ->assertOk()
            ->json('data.accounts'))
            ->flatMap(fn (array $a) => array_column($a['locations'], 'title'))
            ->all();

        $this->assertContains('J. Peterson Design, LLC', $titles);
    }
}
