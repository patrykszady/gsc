<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use Tests\TestCase;

/**
 * The town nav sub-pages (about/services), contact, and a retired town's
 * lead-pipe page (which follows its neighbours instead of answering 404)
 * are indexable regardless of proof.
 *
 * A town's projects/testimonials lists and its service spokes are
 * different: opened without proof 2026-09-14/2026-10-02, then gated again
 * 2026-10-10 once Search Console showed the declined spokes were
 * overwhelmingly towns without a project or review of their own — see
 * AreaServiceDemandGateTest for that gate's own coverage. A town with real
 * proof keeps all of them indexed either way.
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

    public function test_a_towns_nav_subpages_and_contact_are_served_without_noindex_regardless_of_proof(): void
    {
        $this->area('Kenilworth', 'kenilworth');

        foreach ([
            '/areas-served/kenilworth/about',
            '/areas-served/kenilworth/services',
            '/areas-served/kenilworth/contact',
        ] as $url) {
            $this->get($url)->assertOk()->assertDontSee('noindex', false);
        }
    }

    public function test_a_towns_lists_and_service_spoke_are_noindexed_without_proof(): void
    {
        $this->area('Kenilworth', 'kenilworth');

        foreach ([
            '/areas-served/kenilworth/services/basement-remodeling',
            '/areas-served/kenilworth/projects',
            '/areas-served/kenilworth/testimonials',
        ] as $url) {
            $this->get($url)->assertOk()->assertSee('noindex', false);
        }
    }

    public function test_a_towns_lists_and_service_spoke_are_served_without_noindex_with_proof(): void
    {
        $this->area('Kenilworth', 'kenilworth');
        \App\Models\Project::create([
            'title' => 'Kenilworth Kitchen Remodel', 'project_type' => 'kitchen',
            'location' => 'Kenilworth, IL', 'is_published' => true,
        ]);
        \Illuminate\Support\Facades\Cache::flush();

        foreach ([
            '/areas-served/kenilworth/services/basement-remodeling',
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
        // Nowhere Grove has no project or review of its own: unlike the
        // always-indexable lead-pipe guide above, its lists are gated by
        // default since 2026-10-10 and stay out of the sitemap.
        $this->assertStringNotContainsString('https://gs.construction/areas-served/nowhere-grove/projects', $xml);
        $this->assertStringNotContainsString('https://gs.construction/areas-served/nowhere-grove/testimonials', $xml);
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
