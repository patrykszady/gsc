<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\AreaServed;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

class SeoReportControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reports_index_lists_the_configured_registry(): void
    {
        $response = $this->getJson('/api/admin/v1/seo/reports', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($response['reports']);
        $this->assertSame(count(config('seo-reports.reports')), $response['stats']['total']);
        $this->assertArrayHasKey('key', $response['reports'][0]);
        $this->assertArrayHasKey('freshness_pct', $response['reports'][0]);
    }

    public function test_show_404s_for_an_unknown_report_key(): void
    {
        $this->getJson('/api/admin/v1/seo/reports/not-a-real-report', $this->adminApiHeaders())
            ->assertNotFound();
    }

    public function test_show_returns_the_placeholder_when_the_file_was_never_generated(): void
    {
        // Isolate from whatever this dev box's real storage/app/reports
        // happens to contain — the registry key exists, the file must not.
        Storage::fake('local');

        $key = array_key_first(config('seo-reports.reports'));

        $data = $this->getJson("/api/admin/v1/seo/reports/{$key}", $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame($key, $data['key']);
        $this->assertFalse($data['exists']);
        $this->assertSame('missing', $data['status']);
        $this->assertStringContainsString('Run now', $data['html']);
    }

    public function test_regenerate_404s_for_an_unknown_report_key(): void
    {
        // Blanket-fake all outbound HTTP so nothing this triggers (some
        // report commands call external APIs) can ever leave the box.
        Http::fake();

        $this->postJson('/api/admin/v1/seo/reports/not-a-real-report/regenerate', [], $this->adminApiHeaders())
            ->assertNotFound();
    }

    public function test_regenerate_runs_the_commands_artisan_command_and_returns_the_refreshed_report(): void
    {
        // seo:health does purely local reads (no Http:: calls) — confirmed
        // by code inspection — but this blanket-fakes HTTP anyway as a
        // second line of defense for every report command, not just this one.
        Http::fake();
        Storage::fake('local');
        Cache::put(Tenancy::cacheKey('admin.seo-reports.health-snapshot'), ['score' => 999, 'pillars' => []], 60);

        $data = $this->postJson('/api/admin/v1/seo/reports/health/regenerate', [], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame('health', $data['key']);
        // The command itself may succeed or fail depending on how much GSC
        // data this environment has (e.g. seo:health can divide by data this
        // empty sqlite test db doesn't have) — either way the controller's
        // try/catch (ported verbatim from the Livewire original) must turn
        // that into a friendly message, never an unhandled 500.
        $this->assertMatchesRegularExpression('/regenerated\.$|^Failed to regenerate: /', $data['message']);
        // regenerate() busts the health-snapshot cache same as the Livewire
        // original — the stale, manually-seeded score of 999 must be gone
        // either way, since the cache is forgotten before the command runs.
        $this->assertNotSame(999, $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->json('data.health.score'));
    }

    public function test_snapshot_returns_the_full_payload_shape_with_no_data_seeded(): void
    {
        $data = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        foreach (['health', 'report_stats', 'diagnostic', 'search', 'trend', 'top_queries', 'top_pages', 'clarity', 'geo', 'ai_traffic', 'gsc_errors'] as $key) {
            $this->assertArrayHasKey($key, $data, "snapshot payload missing \"{$key}\"");
        }

        $this->assertFalse($data['diagnostic']['available']);
        $this->assertSame([], $data['top_queries']);
        $this->assertSame([], $data['top_pages']);
        $this->assertArrayHasKey('channels', $data['search']);
        $this->assertArrayHasKey('gsc', $data['search']['channels']);
    }

    public function test_snapshot_accepts_trend_and_top_controls(): void
    {
        $data = $this->getJson('/api/admin/v1/seo/snapshot?trend_days=30&trend_metric=impressions&top_days=90&top_queries_sort=impressions&top_queries_dir=asc', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        // 30 days of trend rows, one per day.
        $this->assertCount(30, $data['trend']);
    }

    public function test_snapshot_refresh_busts_the_cached_health_and_search_snapshots(): void
    {
        Cache::put(Tenancy::cacheKey('admin.seo-reports.health-snapshot'), ['score' => 999, 'pillars' => []], 60);

        $data = $this->postJson('/api/admin/v1/seo/snapshot/refresh', [], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame('Dashboard metrics refreshed.', $data['message']);
        // The stale, manually-seeded score of 999 must be gone.
        $this->assertNotSame(999, $data['health']['score']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/v1/seo/reports')->assertUnauthorized();
    }

    public function test_dashboard_carries_map_pack_visibility_with_towns_and_trend(): void
    {
        Cache::flush();
        $area = AreaServed::create(['city' => 'Prospect Heights', 'slug' => 'prospect-heights', 'latitude' => 42.0953, 'longitude' => -87.9373]);
        $grid = [['lat' => 42.1028, 'lng' => -87.9276, 'rank' => 4], ['lat' => 42.30, 'lng' => -87.60, 'rank' => false]];
        $raw = fn (array $extra) => json_encode(['detail' => array_merge(['grid' => $grid, 'points_total' => 2, 'pack_leaders' => [['business' => 'Prism Kitchen', 'appearances' => 15]], 'public_url' => 'https://localrankingtracker.com/scan-report/x/y/', 'radius' => '15mi'], $extra)]);
        foreach ([['2026-08-28 12:00:00', 9.0, 0.0], ['2026-09-04 12:00:00', 4.0, 0.0]] as [$at, $arp, $solv]) {
            DB::table('map_pack_scans')->insert(['site_id' => $area->site_id, 'scan_id' => md5($at), 'keyword' => 'bathroom remodeling', 'scanned_at' => $at, 'arp' => $arp, 'atrp' => 20.9, 'solv' => $solv, 'grid_points' => 11, 'in_top3' => 0, 'raw' => $raw([]), 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('map_pack_competitors')->insert(['site_id' => $area->site_id, 'place_id' => 'p1', 'keyword' => 'bathroom remodeling', 'name' => 'Prism Kitchen', 'url' => 'https://prism.test/', 'host' => 'prism.test', 'rating' => 5.0, 'reviews' => 53, 'claimed' => true, 'categories' => json_encode(['Kitchen remodeler', 'Bathroom remodeler']), 'scan_id' => md5('2026-09-04 12:00:00'), 'scanned_at' => '2026-09-04 12:00:00', 'pack_points' => 15, 'seen_points' => 23, 'best_rank' => 1, 'site_services' => json_encode(['Kitchen', 'Bathroom']), 'site_towns' => json_encode(['Highland Park']), 'site_title' => 'Prism', 'site_fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $lf = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->assertOk()->json('data.map_pack');
        $this->assertTrue($lf['available']);
        $k = $lf['keywords'][0];
        $this->assertSame('bathroom remodeling', $k['keyword']);
        $this->assertEquals(4.0, $k['arp']);
        $this->assertEquals(9.0, $k['prev']['arp']);
        $this->assertSame(1, $k['found']);
        $this->assertSame('Prospect Heights', $k['towns'][0]['city']);
        $this->assertSame(4, $k['towns'][0]['best_rank']);
        $this->assertSame('Prism Kitchen', $k['pack_leaders'][0]['business']);
        $this->assertSame(53, $k['pack_leaders'][0]['reviews']);
        $this->assertSame(['Kitchen', 'Bathroom'], $k['pack_leaders'][0]['services']);
        // map_pack_competitors.claimed/.categories were stored but never returned.
        $this->assertTrue($k['pack_leaders'][0]['claimed']);
        $this->assertSame(['Kitchen remodeler', 'Bathroom remodeler'], $k['pack_leaders'][0]['categories']);
        $this->assertSame(53, $k['review_gap']['leader']);
        $this->assertSame(53, $k['review_gap']['gap']);
    }

    public function test_map_pack_snapshot_reshapes_the_raw_grid_into_a_heatmap_array(): void
    {
        Cache::flush();
        $area = AreaServed::create(['city' => 'Prospect Heights', 'slug' => 'prospect-heights', 'latitude' => 42.0953, 'longitude' => -87.9373]);
        // A 2x2 grid, row-major: [rank 1, not-found], [not-found, rank 4].
        $grid = [
            ['lat' => 42.11, 'lng' => -87.93, 'rank' => 1],
            ['lat' => 42.11, 'lng' => -87.90, 'rank' => false],
            ['lat' => 42.09, 'lng' => -87.93, 'rank' => false],
            ['lat' => 42.09, 'lng' => -87.90, 'rank' => 4],
        ];
        $raw = json_encode(['detail' => ['grid' => $grid, 'points_total' => 4]]);
        DB::table('map_pack_scans')->insert([
            'site_id' => $area->site_id, 'scan_id' => 'grid-1', 'keyword' => 'kitchen remodeling', 'scanned_at' => now(),
            'arp' => 2.5, 'atrp' => 2.5, 'solv' => 50, 'grid_points' => 2, 'in_top3' => 2, 'raw' => $raw,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $k = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->assertOk()->json('data.map_pack.keywords.0');
        $this->assertSame([[1, null], [null, 4]], $k['heatmap_grid']);
    }

    public function test_ranking_snapshot_reports_the_distribution_per_engine_without_double_counting(): void
    {
        Cache::flush();
        $row = fn (array $overrides) => array_merge([
            'site_id' => null, 'location' => null, 'city_slug' => null, 'gsc_match_title' => null, 'result_count' => null,
            'top_results' => json_encode([]), 'meta' => json_encode([]),
        ], $overrides);

        Carbon::setTestNow('2026-09-01 06:00:00');
        DB::table('seo_rank_snapshots')->insert([
            $row(['engine' => 'gsc', 'query' => 'kitchen remodeling naperville', 'gsc_position' => 7, 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]),
            $row(['engine' => 'google', 'query' => 'kitchen remodeling naperville', 'gsc_position' => 6, 'top_results' => json_encode(['old-comp.com']), 'meta' => json_encode(['local_pack_present' => false]), 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]),
            $row(['engine' => 'gsc', 'query' => 'bathroom remodeling', 'gsc_position' => 18, 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]),
        ]);

        Carbon::setTestNow('2026-09-08 06:00:00');
        DB::table('seo_rank_snapshots')->insert([
            $row(['engine' => 'gsc', 'query' => 'kitchen remodeling naperville', 'gsc_position' => 5, 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]),
            $row(['engine' => 'google', 'query' => 'kitchen remodeling naperville', 'gsc_position' => 3,
                'top_results' => json_encode(['comp1.com', 'comp2.com']),
                'meta' => json_encode(['local_pack_present' => true, 'matched_url' => 'https://gs.construction/kitchen-remodeling-naperville']),
                'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]),
            $row(['engine' => 'gsc', 'query' => 'bathroom remodeling', 'gsc_position' => 15, 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]),
        ]);

        $rk = $this->getJson('/api/admin/v1/seo/snapshot?rank_days=7', $this->adminApiHeaders())->assertOk()->json('data.rankings');

        // Backward-compatible merged key the admin blade reads today: deduped
        // one row per query (live SERP preferred), not double-counted.
        $this->assertSame(2, $rk['current']['tracked']);
        $this->assertSame(1, $rk['current']['top3']);
        $this->assertSame(2, $rk['current']['top20']);

        // Per-engine, uncorrupted by the other engine's rows for the same query.
        $this->assertSame('Google Search Console', $rk['search_console']['label']);
        $this->assertSame(2, $rk['search_console']['current']['tracked']);
        $this->assertSame(1, $rk['search_console']['current']['top10'], 'position 5');
        $this->assertSame(1, $rk['live_serp']['current']['tracked'], 'only the naperville query has a live SERP row');
        $this->assertSame(1, $rk['live_serp']['current']['top3']);
        $this->assertSame(1, $rk['live_serp']['prior']['tracked']);

        // Local-pack share is computed over the live SERP checks only.
        $this->assertEquals(100.0, $rk['local_pack']['current']);
        $this->assertEquals(0.0, $rk['local_pack']['prior']);

        // Per-query detail: top domains + matched URL, never returned before.
        $queries = collect($rk['queries'])->keyBy(fn ($q) => $q['engine'].'|'.$q['query']);
        $liveRow = $queries['google|kitchen remodeling naperville'];
        $this->assertSame(3, $liveRow['position']);
        $this->assertSame(6, $liveRow['position_prev']);
        $this->assertSame(['comp1.com', 'comp2.com'], $liveRow['top_results']);
        $this->assertSame('https://gs.construction/kitchen-remodeling-naperville', $liveRow['matched_url']);
        $this->assertTrue($liveRow['local_pack_present']);
    }

    public function test_dataforseo_snapshot_surfaces_keyword_churn_ai_answer_text_link_gap_reasons_and_balance(): void
    {
        Cache::flush();
        DB::table('seo_domain_overviews')->insert([
            ['site_id' => null, 'domain' => 'gs.construction', 'is_us' => true, 'date' => '2026-08-25', 'pos_1' => 5, 'pos_2_3' => 3, 'pos_4_10' => 2, 'pos_11_20' => 1, 'keywords_total' => 11, 'etv' => 100, 'is_new' => 2, 'is_lost' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => null, 'domain' => 'gs.construction', 'is_us' => true, 'date' => '2026-09-01', 'pos_1' => 6, 'pos_2_3' => 3, 'pos_4_10' => 2, 'pos_11_20' => 1, 'keywords_total' => 12, 'etv' => 120, 'is_new' => 5, 'is_lost' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $prospect = fn (array $overrides) => array_merge([
            'site_id' => null, 'rank' => 40, 'competitor_count' => 2, 'links_to_us' => false, 'spam_score' => null,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides);
        DB::table('seo_backlink_prospects')->insert([
            $prospect(['domain' => 'goodgap.test', 'links_to' => json_encode(['a.com' => 1, 'b.com' => 1])]),
            $prospect(['domain' => 'toofew.test', 'links_to' => json_encode(['a.com' => 1]), 'competitor_count' => 1]),
            $prospect(['domain' => 'spammy.test', 'links_to' => json_encode(['a.com' => 1, 'b.com' => 1]), 'spam_score' => 60]),
            $prospect(['domain' => 'freehost.blogspot.com', 'links_to' => json_encode(['a.com' => 1, 'b.com' => 1])]),
        ]);
        DB::table('seo_ai_mentions')->insert([
            ['site_id' => null, 'platform' => 'chatgpt', 'prompt' => 'best kitchen remodeler in Mundelein', 'town' => 'Mundelein', 'mentioned' => true, 'businesses_named' => json_encode(['GS Construction']), 'answer_excerpt' => str_repeat('a', 400), 'asked_on' => '2026-09-08', 'created_at' => now(), 'updated_at' => now()],
        ]);
        Cache::put('seo.dataforseo.balance', 42.5, now()->addDay());

        $dfs = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->assertOk()->json('data.dataforseo');

        $sov = collect($dfs['share_of_voice'])->firstWhere('domain', 'gs.construction');
        $this->assertSame(5, $sov['is_new']);
        $this->assertSame(2, $sov['is_new_prev']);
        $this->assertSame(3, $sov['is_lost']);
        $this->assertSame(1, $sov['is_lost_prev']);

        $this->assertSame(1, $dfs['link_gap_hidden']['competitor_count_out_of_range']);
        $this->assertSame(1, $dfs['link_gap_hidden']['spam_score']);
        $this->assertSame(1, $dfs['link_gap_hidden']['free_host']);
        $this->assertSame(1, $dfs['link_gap_hidden']['visible']);

        $mundelein = $dfs['ai_mentions']['by_town_detail']['Mundelein'][0];
        $this->assertSame('best kitchen remodeler in Mundelein', $mundelein['prompt']);
        $this->assertSame(300, mb_strlen($mundelein['answer_excerpt']));
        $this->assertSame(['GS Construction'], $mundelein['businesses_named']);

        $this->assertEquals(42.5, $dfs['balance']);
    }

    public function test_dataforseo_snapshot_balance_is_null_when_track_b_has_not_written_it(): void
    {
        Cache::flush();

        $dfs = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->assertOk()->json('data.dataforseo');

        $this->assertNull($dfs['balance']);
    }

    public function test_keyword_research_snapshot_carries_prior_run_totals_for_a_chevron(): void
    {
        $seedKeywords = function (int $n, int $volumeEach): void {
            DB::table('seo_keywords')->truncate();
            for ($i = 0; $i < $n; $i++) {
                DB::table('seo_keywords')->insert([
                    'site_id' => null, 'keyword' => "kitchen remodel {$i}", 'volume' => $volumeEach,
                    'opportunity' => 5, 'researched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        };

        Cache::flush();
        Carbon::setTestNow('2026-09-01 06:00:00');
        $seedKeywords(10, 100);
        $first = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->assertOk()->json('data.keyword_research');
        $this->assertSame(10, $first['total']);
        $this->assertNull($first['total_prev'], 'no prior run recorded yet');

        // Bust only the 15-minute snapshot cache — Cache::flush() would also wipe
        // the rolling totals ledger this feature depends on for its own history.
        Cache::forget(Tenancy::cacheKey('seo_reports_keywords_v1'));
        Carbon::setTestNow('2026-09-08 06:00:00');
        $seedKeywords(14, 100);
        $second = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->assertOk()->json('data.keyword_research');
        $this->assertSame(14, $second['total']);
        $this->assertSame(10, $second['total_prev']);
        $this->assertSame(1000, $second['volume_total_prev']);
        $this->assertSame(1400, $second['volume_total']);
    }
}
