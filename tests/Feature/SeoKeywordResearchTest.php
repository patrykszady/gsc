<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeoKeywordResearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dataforseo.login' => 'u', 'services.dataforseo.password' => 'p']);
        AreaServed::create(['city' => 'Kenilworth', 'slug' => 'kenilworth']);
        DB::table('map_pack_competitors')->insert(['site_id' => null, 'place_id' => 'p1', 'keyword' => 'kitchen remodeling', 'name' => 'Prism', 'url' => 'https://prism.test/', 'host' => 'prism.test', 'pack_points' => 9, 'seen_points' => 12, 'created_at' => now(), 'updated_at' => now()]);
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

    public function test_fails_closed_when_the_balance_check_itself_fails(): void
    {
        // A failed balance() call (null) used to slip the old guard
        // (`$balance !== null && ...`) entirely and let the run spend.
        Http::fake(['*/appendix/user_data' => Http::response('', 500)]);

        $this->artisan('seo:keyword-research', ['--budget' => 3])
            ->expectsOutputToContain('balance unknown')
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
                ['keyword_data' => ['keyword' => 'kitchen cabinets', 'keyword_info' => ['search_volume' => 246000]], 'ranked_serp_element' => ['serp_item' => ['rank_absolute' => 40]]],
                ['keyword_data' => ['keyword' => 'concrete slab contractors', 'keyword_info' => ['search_volume' => 14800]], 'ranked_serp_element' => ['serp_item' => ['rank_absolute' => 12]]],
                ['keyword_data' => ['keyword' => 'pizza kenilworth', 'keyword_info' => ['search_volume' => 900]], 'ranked_serp_element' => ['serp_item' => ['rank_absolute' => 1]]],
            ]]]]]]),
            '*/search_volume/live' => Http::response(['tasks' => [['cost' => 0.08, 'status_code' => 20000, 'result' => [
                ['keyword' => 'kenilworth home remodeling', 'search_volume' => 320, 'cpc' => 12.5, 'competition_index' => 40],
                ['keyword' => 'kitchen remodeling kenilworth', 'search_volume' => 50, 'cpc' => null, 'competition_index' => 10],
                ['keyword' => 'kitchen cabinets', 'search_volume' => 246000, 'cpc' => 2.0, 'competition_index' => 80],
                ['keyword' => 'concrete slab contractors', 'search_volume' => 14800, 'cpc' => 3.0, 'competition_index' => 20],
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
        $national = DB::table('seo_keywords')->where('keyword', 'kitchen cabinets')->first();
        $this->assertSame(246000, (int) $national->volume);
        $this->assertSame(0.0, (float) $national->opportunity, 'a national head term is stored but is no opportunity');
        $this->assertSame(0.0, (float) DB::table('seo_keywords')->where('keyword', 'concrete slab contractors')->value('opportunity'), 'not our trade');
        $this->assertNotNull(DB::table('seo_keywords')->where('keyword', 'kitchen remodeling kenilworth')->first(), 'generated town×service phrases are in the universe');
    }

    public function test_dry_run_domain_universe_never_includes_an_aggregator_host(): void
    {
        // A directory host with far more pack_points than the real rival
        // seeded in setUp() — it must still be dropped, and even ranking
        // first must not save it.
        DB::table('map_pack_competitors')->insert(['site_id' => null, 'place_id' => 'p2', 'keyword' => 'kitchen remodeling', 'name' => 'A Facebook Page', 'url' => 'https://facebook.com/page', 'host' => 'facebook.com', 'pack_points' => 99, 'seen_points' => 99, 'created_at' => now(), 'updated_at' => now()]);

        Storage::fake('local');
        Storage::disk('local')->put('reports/competitor-discovery.json', json_encode([
            'generated_at' => now()->toIso8601String(),
            'domains' => [['host' => 'houzz.com', 'areas' => 10, 'best_pos' => 1, 'known' => false]],
        ]));

        Artisan::call('seo:keyword-research', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('prism.test', $output);
        $this->assertStringNotContainsString('facebook.com', $output);
        $this->assertStringNotContainsString('houzz.com', $output);
    /**
     * DataForSeoService::$lastError is set-only — it is never cleared back to
     * null — so the old per-item heuristic ("no error, or the error text is
     * unchanged from before this call, means success") read a SECOND
     * competitor call that fails with the exact same error text as a
     * success. Faking every competitor call with an identical failure
     * reproduces that: this must fail closed, not exit 0.
     */
    public function test_two_consecutive_identical_failures_are_not_read_as_success(): void
    {
        DB::table('map_pack_competitors')->insert([
            ['site_id' => null, 'place_id' => 'p2', 'keyword' => 'kitchen remodeling', 'name' => 'C2', 'host' => 'competitor2.test', 'pack_points' => 50, 'seen_points' => 9, 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => null, 'place_id' => 'p3', 'keyword' => 'kitchen remodeling', 'name' => 'C3', 'host' => 'competitor3.test', 'pack_points' => 40, 'seen_points' => 9, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 25]]]]]]);
            }
            if (str_contains($request->url(), 'ranked_keywords')) {
                // The exact same failure, twice in a row.
                return Http::response(['tasks' => [['cost' => 0, 'status_code' => 40501, 'status_message' => 'Invalid Field: target.']]]);
            }

            return Http::response([], 200);
        });

        $this->artisan('seo:keyword-research', ['--budget' => 3, '--competitors' => 2])
            ->expectsOutputToContain('Every competitor domain failed')
            ->assertExitCode(1);
    }
}
