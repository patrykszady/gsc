<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoKeywordResearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dataforseo.login' => 'u', 'services.dataforseo.password' => 'p']);
        AreaServed::create(['city' => 'Kenilworth', 'slug' => 'kenilworth']);
        DB::table('local_falcon_competitors')->insert(['site_id' => null, 'place_id' => 'p1', 'keyword' => 'kitchen remodeling', 'name' => 'Prism', 'url' => 'https://prism.test/', 'host' => 'prism.test', 'pack_points' => 9, 'seen_points' => 12, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_refuses_to_run_when_the_balance_cannot_cover_the_estimate(): void
    {
        Http::fake(['*/appendix/user_data' => Http::response(['tasks' => [['result' => [['money' => ['balance' => 0.05]]]]]])]);

        $this->artisan('seo:keyword-research', ['--budget' => 3])
            ->expectsOutputToContain('cannot cover this run')
            ->assertExitCode(1);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'search_volume') || str_contains($r->url(), 'ranked_keywords'));
        $this->assertSame(0, DB::table('seo_keywords')->count());
    }

    public function test_builds_the_universe_and_writes_volumes_positions_and_competitor_coverage(): void
    {
        DB::table('gsc_query_metrics')->insert(['site_id' => null, 'date' => now()->subDays(5)->toDateString(), 'site_url' => 'sc-domain:gs.construction', 'query' => 'kenilworth home remodeling', 'page' => 'https://gs.construction/areas-served/kenilworth', 'country' => 'usa', 'device' => 'MOBILE', 'impressions' => 400, 'clicks' => 0, 'position' => 7.5, 'ctr' => 0, 'dim_hash' => 'h1', 'created_at' => now(), 'updated_at' => now()]);

        Http::fake([
            '*/appendix/user_data' => Http::response(['tasks' => [['result' => [['money' => ['balance' => 25.0]]]]]]),
            '*/ranked_keywords/live' => Http::response(['tasks' => [['cost' => 0.02, 'status_code' => 20000, 'result' => [['items' => [
                ['keyword_data' => ['keyword' => 'luxury kitchen remodel kenilworth', 'keyword_info' => ['search_volume' => 90], 'keyword_properties' => ['keyword_difficulty' => 22]], 'ranked_serp_element' => ['serp_item' => ['rank_absolute' => 4, 'url' => 'https://prism.test/kenilworth']]],
                ['keyword_data' => ['keyword' => 'pizza kenilworth', 'keyword_info' => ['search_volume' => 900]], 'ranked_serp_element' => ['serp_item' => ['rank_absolute' => 1]]],
            ]]]]]]),
            '*/search_volume/live' => Http::response(['tasks' => [['cost' => 0.08, 'status_code' => 20000, 'result' => [
                ['keyword' => 'kenilworth home remodeling', 'search_volume' => 320, 'cpc' => 12.5, 'competition_index' => 40],
                ['keyword' => 'kitchen remodeling kenilworth', 'search_volume' => 50, 'cpc' => null, 'competition_index' => 10],
            ]]]]),
        ]);

        $this->artisan('seo:keyword-research', ['--budget' => 3])->assertExitCode(0);

        $k = DB::table('seo_keywords')->where('keyword', 'kenilworth home remodeling')->first();
        $this->assertSame(320, (int) $k->volume);
        $this->assertEquals(7.5, $k->our_position);
        $this->assertSame(400, (int) $k->our_impressions);
        $this->assertSame('home-remodeling', $k->service);
        $this->assertSame('Kenilworth', $k->city);

        $c = DB::table('seo_keywords')->where('keyword', 'luxury kitchen remodel kenilworth')->first();
        $this->assertSame(90, (int) $c->volume);
        $this->assertSame('luxury', $c->modifier);
        $this->assertSame(4, (int) $c->competitor_best_position);
        $this->assertStringContainsString('prism.test', $c->competitor_domains);
        $this->assertGreaterThan(0, (float) $c->opportunity);

        $this->assertNull(DB::table('seo_keywords')->where('keyword', 'pizza kenilworth')->first(), 'non-remodeling terms are dropped');
        $this->assertNotNull(DB::table('seo_keywords')->where('keyword', 'kitchen remodeling kenilworth')->first(), 'generated town×service phrases are in the universe');
    }
}
