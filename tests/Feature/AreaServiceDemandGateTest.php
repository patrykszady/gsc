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

    public function test_service_page_indexes_on_demand_without_proof(): void
    {
        Cache::flush();
        $a = $this->area('Kenilworth', 'kenilworth');
        $this->assertFalse(AreaSeoPolicy::shouldIndex($a, 'service', 'home-remodeling'), 'no proof, no demand → noindex');

        Cache::flush();
        foreach (range(1, 4) as $i) {
            DB::table('gsc_query_metrics')->insert(['site_id' => $a->site_id, 'date' => now()->subDays(4 + $i)->toDateString(), 'site_url' => 'sc-domain:gs.construction', 'query' => 'kenilworth home remodeling and renovation services', 'page' => 'https://gs.construction/areas-served/kenilworth', 'country' => 'usa', 'device' => 'MOBILE', 'impressions' => 40, 'clicks' => 0, 'position' => 6.0, 'ctr' => 0, 'dim_hash' => md5('k' . $i), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertSame(160, AreaSeoPolicy::demandImpressions($a, 'home-remodeling'));
        $this->assertTrue(AreaSeoPolicy::shouldIndex($a, 'service', 'home-remodeling'));
        $this->assertFalse(AreaSeoPolicy::shouldIndex($a, 'service', 'kitchen-remodeling'), 'demand is per service');
        $this->assertFalse(AreaSeoPolicy::shouldIndex($a, 'projects'), 'proof-only spokes stay gated');
    }
}
