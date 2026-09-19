<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Site;
use App\Support\GoogleBusinessListing;
use App\Support\Tenancy;
use Tests\TestCase;

/**
 * gs.construction/review drops a happy customer straight on the Google
 * write-review form. The place id it used came from the deployment's env,
 * which is gs.construction's — so the same link on another tenant's domain
 * would have sent that business's customers to GS Construction's form, and
 * their review to the wrong company.
 */
class ReviewShortlinkPerSiteTest extends TestCase
{
    public function test_the_default_site_sends_people_to_its_own_review_form(): void
    {
        config(['services.google.business_profile.place_id' => 'ChIJgsconstruction']);

        $this->get('https://gs.construction/review')
            ->assertRedirect('https://search.google.com/local/writereview?placeid=ChIJgsconstruction');
    }

    public function test_another_tenant_never_sends_its_customers_to_the_default_sites_form(): void
    {
        config(['services.google.business_profile.place_id' => 'ChIJgsconstruction']);

        $site = Site::query()->where('slug', 'jpeterson')->firstOrFail();
        $site->forceFill(['is_active' => true])->save();
        Site::forgetActive();

        $location = $this->get('https://jpeterson-design.com/review')->assertRedirectContains('google.com')
            ->headers->get('location');

        $this->assertStringNotContainsString('ChIJgsconstruction', (string) $location);
        $this->assertStringContainsString('J.+Peterson+Design', (string) $location, 'her own name, until she links a listing');
    }

    public function test_once_a_tenant_has_its_own_listing_the_link_is_that_listing(): void
    {
        $site = Site::query()->where('slug', 'jpeterson')->firstOrFail();
        $site->forceFill(['is_active' => true])->save();
        Site::forgetActive();

        Tenancy::for($site, fn () => PlatformSetting::put(GoogleBusinessListing::SETTING_PLACE_ID, 'ChIJherplace'));

        $this->get('https://jpeterson-design.com/review')
            ->assertRedirect('https://search.google.com/local/writereview?placeid=ChIJherplace');
    }
}
