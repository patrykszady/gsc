<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\Project;
use App\Support\SEO\AreaSeoPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A town with no completed project used to have every service page noindexed.
 * With real Search Console demand for "<town> <service>" the page is indexed.
 */
class AreaServiceDemandGateTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function area(string $city, string $slug): AreaServed
    {
        return AreaServed::create(['city' => $city, 'slug' => $slug, 'local_intro' => str_repeat('Unique local copy about the town. ', 60)]);
    }

    /**
     * Since 2026-10-10 (regated after the 2026-09-14/2026-10-02 opening —
     * Search Console showed the declined spokes were overwhelmingly towns
     * without proof or demand) area_index_service_pages and
     * area_index_list_spokes_without_proof both default false again: a town
     * with no project or review of its own, and no researched demand, keeps
     * its service spoke and its projects/testimonials lists noindexed. Its
     * nav sub-pages (about/services, area_index_subpages) and its contact
     * page (area_index_contact_pages) stay open regardless — those flags
     * were not touched.
     */
    public function test_a_town_without_proof_or_demand_keeps_its_lists_and_service_spoke_noindexed(): void
    {
        Cache::flush();
        $a = $this->area('Kenilworth', 'kenilworth');
        foreach (['about', 'services'] as $page) {
            $this->assertTrue(AreaSeoPolicy::shouldIndex($a, $page), "{$page} is indexable");
        }
        $this->assertTrue(AreaSeoPolicy::shouldIndex($a, 'contact'));

        $this->assertFalse(AreaSeoPolicy::shouldIndex($a, 'service', 'basement-remodeling'), 'no proof, no demand: gated by default');
        $this->assertFalse(AreaSeoPolicy::shouldIndex($a, 'projects'), 'no proof: the lists are gated by default');
        $this->assertFalse(AreaSeoPolicy::shouldIndex($a, 'testimonials'), 'no proof: the lists are gated by default');

        // Both flags remain a one-line revert to the fully-open world.
        config(['seo.area_index_service_pages' => true, 'seo.area_index_list_spokes_without_proof' => true]);
        $this->assertTrue(AreaSeoPolicy::shouldIndex($a, 'service', 'basement-remodeling'), 'the flag reopens the service spoke');
        $this->assertTrue(AreaSeoPolicy::shouldIndex($a, 'projects'), 'the flag reopens the lists');
        $this->assertTrue(AreaSeoPolicy::shouldIndex($a, 'testimonials'), 'the flag reopens the lists');
        config(['seo.area_index_service_pages' => false, 'seo.area_index_list_spokes_without_proof' => false]);

        $bare = AreaServed::create(['city' => 'Nowhere', 'slug' => 'nowhere']);
        $this->assertFalse(AreaSeoPolicy::shouldIndex($bare, 'home'));
        $this->assertFalse(AreaSeoPolicy::shouldIndex($bare, 'contact'), 'a template town does not index its sub-pages either');
        $this->assertFalse(AreaSeoPolicy::shouldIndex($bare, 'service', 'kitchen-remodeling'));
    }

    /**
     * isPriority() short-circuits the gate before either flag is read, so a
     * town with genuine local proof — one published project OR one visible
     * review of its own — keeps every spoke indexed even with both flags at
     * their new (gated) default.
     */
    public function test_a_town_with_proof_keeps_every_variant_indexed_with_the_gates_on(): void
    {
        Cache::flush();
        $a = $this->area('Kenilworth', 'kenilworth');
        Project::create([
            'title' => 'Kenilworth Kitchen Remodel', 'project_type' => 'kitchen',
            'location' => 'Kenilworth, IL', 'is_published' => true,
        ]);
        Cache::flush();

        $this->assertTrue(AreaSeoPolicy::isPriority($a));
        $this->assertTrue(AreaSeoPolicy::shouldIndex($a, 'projects'), 'proof opens the projects list');
        $this->assertTrue(AreaSeoPolicy::shouldIndex($a, 'testimonials'), 'proof opens the testimonials list too');
        $this->assertTrue(AreaSeoPolicy::shouldIndex($a, 'service', 'basement-remodeling'), 'proof opens every service spoke, not just the matching trade');
        $this->assertTrue(AreaSeoPolicy::shouldIndex($a, 'contact'));
    }

    public function test_service_page_indexes_on_demand_without_proof(): void
    {
        // The proof/demand gates, exactly as before the flags: turning them
        // off must be a one-line revert, so pin what that world looks like.
        config(['seo.area_index_subpages' => false, 'seo.area_index_service_pages' => false]);
        Cache::flush();
        $a = $this->area('Kenilworth', 'kenilworth');
        $this->assertFalse(AreaSeoPolicy::shouldIndex($a, 'contact'), 'gates on: nav sub-pages stay out');
        $this->assertFalse(AreaSeoPolicy::shouldIndex($a, 'service', 'home-remodeling'), 'no proof, no demand → noindex');

        Cache::flush();
        foreach (range(1, 4) as $i) {
            DB::table('gsc_query_metrics')->insert(['site_id' => $a->site_id, 'date' => now()->subDays(4 + $i)->toDateString(), 'site_url' => 'sc-domain:gs.construction', 'query' => 'kenilworth home remodeling and renovation services', 'page' => 'https://gs.construction/areas-served/kenilworth', 'country' => 'usa', 'device' => 'MOBILE', 'impressions' => 40, 'clicks' => 0, 'position' => 6.0, 'ctr' => 0, 'dim_hash' => md5('k'.$i), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertSame(160, AreaSeoPolicy::demandImpressions($a, 'home-remodeling'));
        $this->assertTrue(AreaSeoPolicy::shouldIndex($a, 'service', 'home-remodeling'));
        $this->assertFalse(AreaSeoPolicy::shouldIndex($a, 'service', 'kitchen-remodeling'), 'demand is per service');
        $this->assertFalse(AreaSeoPolicy::shouldIndex($a, 'projects'), 'proof-only spokes stay gated');
    }
}
