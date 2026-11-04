<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\AreaServiceContent;
use App\Models\Project;
use App\Support\SEO\AreaSeoPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Every town has every service page, mudroom included (Patryk, 2026-11-03).
 * /areas-served/{town}/services/mudroom-remodeling was a 301 to the
 * site-wide mudroom page; it is a town page now, linked like the other five
 * and indexed by the same AreaSeoPolicy rules — a town's own mudroom job
 * makes it indexable, a town without proof or demand stays noindex.
 */
class AreaMudroomSpokeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function town(string $city, string $slug): AreaServed
    {
        return AreaServed::create([
            'city' => $city, 'slug' => $slug, 'state' => 'IL',
            'local_intro' => str_repeat("{$city} copy about the town. ", 40),
        ]);
    }

    public function test_every_town_has_a_mudroom_page_with_its_own_title_and_canonical(): void
    {
        $this->town('Kenilworth', 'kenilworth');
        Cache::flush();

        $page = $this->get('/areas-served/kenilworth/services/mudroom-remodeling')->assertOk();

        $page->assertSee('Kenilworth Mudroom Remodeling');
        $page->assertSee('Mudroom and laundry remodeling in Kenilworth, IL');
        $page->assertSee('<link rel="canonical" href="'.url('/areas-served/kenilworth/services/mudroom-remodeling').'"', false);
        $this->assertContains('mudroom-remodeling', AreaServiceContent::services());
    }

    public function test_a_towns_own_mudroom_job_leads_the_page_and_makes_it_indexable(): void
    {
        $mapleton = $this->town('Mapleton', 'mapleton');
        Project::create(['title' => 'Mapleton Mudroom & Laundry', 'project_type' => 'mudroom', 'location' => 'Mapleton, IL', 'is_published' => true]);
        Cache::flush();

        $page = $this->get('/areas-served/mapleton/services/mudroom-remodeling')->assertOk();

        $page->assertSeeInOrder(["Mudroom and laundry room remodeling we've completed in Mapleton, IL", 'Mapleton Mudroom &amp; Laundry'], false);
        $page->assertDontSee('noindex', false);
        $this->assertTrue(AreaSeoPolicy::shouldIndex($mapleton, 'service', 'mudroom-remodeling'));
    }

    public function test_a_town_without_proof_or_demand_keeps_its_mudroom_page_out_of_the_index_like_its_other_spokes(): void
    {
        config(['seo.area_index_service_pages' => false]);
        $kenilworth = $this->town('Kenilworth', 'kenilworth');
        Cache::flush();

        $this->assertFalse(AreaSeoPolicy::shouldIndex($kenilworth, 'service', 'mudroom-remodeling'));
        $this->assertFalse(AreaSeoPolicy::shouldIndex($kenilworth, 'service', 'kitchen-remodeling'));
        $this->get('/areas-served/kenilworth/services/mudroom-remodeling')->assertOk()->assertSee('noindex', false);
    }

    public function test_the_town_pages_and_the_mudroom_hub_link_every_towns_mudroom_page(): void
    {
        $this->town('Mapleton', 'mapleton');
        $this->town('Kenilworth', 'kenilworth');
        Cache::flush();

        $this->get('/areas-served/kenilworth')->assertOk()
            ->assertSee('/areas-served/kenilworth/services/mudroom-remodeling', false);
        $this->get('/areas-served/kenilworth/services/kitchen-remodeling')->assertOk()
            ->assertSee('/areas-served/kenilworth/services/mudroom-remodeling', false);
        $this->get('/areas-served/kenilworth/services')->assertOk()
            ->assertSee('/areas-served/kenilworth/services/mudroom-remodeling', false);

        $this->get('/services/mudroom-remodeling')->assertOk()
            ->assertSee('/areas-served/mapleton/services/mudroom-remodeling', false)
            ->assertSee('/areas-served/kenilworth/services/mudroom-remodeling', false);
    }
}
