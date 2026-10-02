<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use Tests\TestCase;

/**
 * The town sub-pages Search Console listed under "Excluded by noindex" are
 * indexable now, and a retired town's lead-pipe page follows its neighbours
 * instead of answering 404. Since 2026-10-02 (Patryk: anything on the public
 * site is indexable) that includes a town's projects and testimonials lists
 * without a project or review of its own, and lead-pipe pages without
 * official city info, all of them sitemapped.
 */
class AreaSubPagesIndexableTest extends TestCase
{
    private function area(string $city, string $slug, ?float $lat = null, ?float $lng = null): AreaServed
    {
        return AreaServed::create([
            'city' => $city, 'slug' => $slug, 'state' => 'IL', 'latitude' => $lat, 'longitude' => $lng,
            'local_intro' => str_repeat('Unique local copy about the town. ', 60),
        ]);
    }

    public function test_a_towns_sub_pages_and_service_pages_are_served_without_noindex(): void
    {
        $this->area('Kenilworth', 'kenilworth');

        foreach ([
            '/areas-served/kenilworth/about',
            '/areas-served/kenilworth/services',
            '/areas-served/kenilworth/services/basement-remodeling',
            '/areas-served/kenilworth/contact',
            '/areas-served/kenilworth/projects',
            '/areas-served/kenilworth/testimonials',
        ] as $url) {
            $this->get($url)->assertOk()->assertDontSee('noindex', false);
        }
    }

    public function test_a_lead_pipe_page_without_official_city_info_is_indexable_and_sitemapped(): void
    {
        $this->area('Nowhere Grove', 'nowhere-grove');
        $this->assertFalse(\App\Support\LeadLineInfo::hasOfficialInfo('nowhere-grove'));

        $this->get('/areas-served/nowhere-grove/lead-pipe-replacement')->assertOk()->assertDontSee('noindex', false);

        $this->artisan('sitemap:generate', ['--url' => 'https://gs.construction'])->assertSuccessful();
        $xml = (string) file_get_contents(\App\Support\Seo\CrawlFiles::sitemapPath());

        $this->assertStringContainsString('https://gs.construction/areas-served/nowhere-grove/lead-pipe-replacement', $xml);
        $this->assertStringContainsString('https://gs.construction/areas-served/nowhere-grove/projects', $xml);
        $this->assertStringContainsString('https://gs.construction/areas-served/nowhere-grove/testimonials', $xml);
    }

    public function test_a_retired_towns_lead_pipe_page_redirects_like_its_other_spokes(): void
    {
        $this->area('Tinley Park', 'tinley-park', 41.5731, -87.7845);

        // Orland Park is in the gazetteer but not served; its nearest served town is Tinley Park.
        $this->get('/areas-served/orland-park/lead-pipe-replacement')
            ->assertStatus(301)
            ->assertRedirect('/areas-served/tinley-park/lead-pipe-replacement');
    }
}
