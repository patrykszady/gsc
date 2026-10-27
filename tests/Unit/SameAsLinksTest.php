<?php

namespace Tests\Unit;

use App\Models\Citation;
use App\Models\Site;
use App\Support\Seo\SameAsLinks;
use Tests\TestCase;

/**
 * SameAsLinks::urls() is the one list every Organization/LocalBusiness/
 * HomeAndConstructionBusiness node's `sameAs` reads from: the socials
 * configured in config/socials.php plus any directory citation that has
 * actually gone live, deduped.
 */
class SameAsLinksTest extends TestCase
{
    public function test_it_includes_the_configured_social_urls(): void
    {
        $urls = SameAsLinks::urls();

        foreach (config('socials') as $profile) {
            $this->assertContains($profile['url'], $urls);
        }
    }

    public function test_it_includes_a_live_citations_listing_url(): void
    {
        Citation::create([
            'site_id' => Site::current()->id,
            'slug' => 'remodeling-directory',
            'name' => 'Remodeling Directory',
            'status' => Citation::STATUS_LIVE,
            'listing_url' => 'https://remodeling-directory.test/gs-construction',
        ]);

        $this->assertContains('https://remodeling-directory.test/gs-construction', SameAsLinks::urls());
    }

    public function test_it_excludes_a_citation_that_is_not_live(): void
    {
        Citation::create([
            'site_id' => Site::current()->id,
            'slug' => 'planned-directory',
            'name' => 'Planned Directory',
            'status' => Citation::STATUS_PLANNED,
            'listing_url' => 'https://planned-directory.test/gs-construction',
        ]);

        $this->assertNotContains('https://planned-directory.test/gs-construction', SameAsLinks::urls());
    }

    public function test_it_excludes_a_live_citation_with_no_listing_url(): void
    {
        Citation::create([
            'site_id' => Site::current()->id,
            'slug' => 'live-no-url',
            'name' => 'Live No URL',
            'status' => Citation::STATUS_LIVE,
            'listing_url' => null,
        ]);

        // Nothing to assert by value — just that building the list doesn't
        // choke on a live row with no URL and doesn't invent an entry for it.
        $this->assertNotContains(null, SameAsLinks::urls());
        $this->assertNotContains('', SameAsLinks::urls());
    }

    public function test_the_list_is_deduplicated(): void
    {
        $social = collect(config('socials'))->first()['url'];

        Citation::create([
            'site_id' => Site::current()->id,
            'slug' => 'same-as-a-social',
            'name' => 'Same As A Social',
            'status' => Citation::STATUS_LIVE,
            'listing_url' => $social,
        ]);

        $urls = SameAsLinks::urls();
        $this->assertSame(count($urls), count(array_unique($urls)));
    }

    public function test_a_live_citation_belonging_to_another_site_is_not_included(): void
    {
        $otherSite = Site::where('id', '!=', Site::current()->id)->first();
        if (! $otherSite) {
            $this->markTestSkipped('No second tenant site seeded in this environment.');
        }

        Citation::create([
            'site_id' => $otherSite->id,
            'slug' => 'other-tenant-directory',
            'name' => 'Other Tenant Directory',
            'status' => Citation::STATUS_LIVE,
            'listing_url' => 'https://other-tenant-directory.test/listing',
        ]);

        $this->assertNotContains('https://other-tenant-directory.test/listing', SameAsLinks::urls());
    }
}
