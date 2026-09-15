<?php

namespace Tests\Feature;

use App\Models\AreaServed;
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
     * Since 2026-09-14 every variant of a town with its own copy is indexable
     * (seo.area_index_subpages / seo.area_index_service_pages). A town
     * without its own copy stays a bare template, spokes included.
     */
    public function test_every_variant_of_a_town_with_its_own_copy_is_indexable(): void
    {
        Cache::flush();
        $a = $this->area('Kenilworth', 'kenilworth');
        foreach (['contact', 'about', 'services', 'projects', 'testimonials'] as $page) {
            $this->assertTrue(AreaSeoPolicy::shouldIndex($a, $page), "{$page} is indexable");
        }
        $this->assertTrue(AreaSeoPolicy::shouldIndex($a, 'service', 'basement-remodeling'), 'no proof, no demand, still indexable');

        $bare = AreaServed::create(['city' => 'Nowhere', 'slug' => 'nowhere']);
        $this->assertFalse(AreaSeoPolicy::shouldIndex($bare, 'home'));
        $this->assertFalse(AreaSeoPolicy::shouldIndex($bare, 'contact'), 'a template town does not index its sub-pages either');
        $this->assertFalse(AreaSeoPolicy::shouldIndex($bare, 'service', 'kitchen-remodeling'));
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
