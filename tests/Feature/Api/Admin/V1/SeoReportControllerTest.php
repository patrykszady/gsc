<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\SeoReportController;
use App\Models\AreaServed;
use App\Models\BingDailyTotal;
use App\Models\GscCoverageState;
use App\Models\GscCoverageStateHistory;
use App\Models\GscDailyTotal;
use App\Support\SeoStorage;
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
        // The behaviour card's page list: present (and empty) even with no
        // per-page rows, so the shared admin view never sees it missing.
        $this->assertSame([], $data['clarity']['pages']);
        $this->assertArrayHasKey('channels', $data['search']);
        $this->assertArrayHasKey('gsc', $data['search']['channels']);
    }

    /**
     * Forty days of Google totals ending three days ago and forty of Bing
     * ending two days ago — Search Console's real lag, with Bing fresher.
     */
    private function seedDailyTotals(): void
    {
        $today = Carbon::parse('2026-09-19');
        Carbon::setTestNow($today);

        for ($i = 0; $i < 40; $i++) {
            GscDailyTotal::create([
                'date' => $today->copy()->subDays(3 + $i)->toDateString(), 'site_url' => 'sc-domain:example.test',
                'clicks' => 5, 'impressions' => 200, 'ctr' => 0.025, 'position' => 8.5,
            ]);
            BingDailyTotal::create([
                'date' => $today->copy()->subDays(2 + $i)->toDateString(), 'site_url' => 'https://example.test/',
                'clicks' => 1, 'impressions' => 20, 'ctr' => 0.05,
            ]);
        }
    }

    public function test_snapshot_accepts_trend_and_top_controls(): void
    {
        $this->seedDailyTotals();

        $data = $this->getJson('/api/admin/v1/seo/snapshot?trend_days=30&top_days=90&top_queries_sort=impressions&top_queries_dir=asc', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        // 30 days of trend rows, one per day, ending on the last day with data.
        $this->assertCount(30, $data['trend']);
        $this->assertSame('Sep 17', $data['trend_through']);
        $this->assertSame('2026-09-17', end($data['trend'])['day']);
    }

    public function test_the_trend_ends_on_the_last_day_with_data_and_never_draws_a_missing_day_as_zero(): void
    {
        $this->seedDailyTotals();

        $trend = $this->getJson('/api/admin/v1/seo/snapshot?trend_days=14', $this->adminApiHeaders())->json('data.trend');
        $last = end($trend);

        // The window ends on Bing's last day. Google has not reported it yet:
        // null, so the chart draws a gap — not a zero that reads as a collapse.
        $this->assertSame('2026-09-17', $last['day']);
        $this->assertNull($last['gsc_clicks']);
        $this->assertNull($last['gsc_impressions']);
        $this->assertNull($last['gsc_ctr']);
        $this->assertSame(1, $last['bing_clicks']);
        $this->assertSame(20, $last['bing_impressions']);
        // Combined is a sum with a missing term: unknown, not "just Bing" —
        // otherwise every chart ended in a plunge to almost nothing.
        $this->assertNull($last['combined_clicks']);
        $this->assertNull($last['combined_impressions']);
        $this->assertNull($last['combined_ctr']);

        // A day both reported carries every metric for both.
        $full = $trend[0];
        $this->assertSame(5, $full['gsc_clicks']);
        $this->assertSame(2.5, $full['gsc_ctr']);
        $this->assertSame(8.5, $full['gsc_position']);
        $this->assertSame(6, $full['combined_clicks']);
        $this->assertSame(220, $full['combined_impressions']);
        $this->assertSame(2.73, $full['combined_ctr']);
        // Position does not add across engines.
        $this->assertNull($full['combined_position']);
    }

    public function test_a_year_window_returns_only_the_days_that_exist(): void
    {
        $this->seedDailyTotals();

        $trend = $this->getJson('/api/admin/v1/seo/snapshot?trend_days=360', $this->adminApiHeaders())->json('data.trend');

        // Forty-one distinct days between the two channels; the 319 blank
        // days before them are not rows.
        $this->assertCount(41, $trend);
        $this->assertSame('2026-08-08', $trend[0]['day']);
        // Bing had not started on Google's first day.
        $this->assertNull($trend[0]['bing_clicks']);
        $this->assertSame(5, $trend[0]['gsc_clicks']);
    }

    public function test_an_unknown_trend_window_falls_back_to_two_weeks(): void
    {
        $this->seedDailyTotals();

        $trend = $this->getJson('/api/admin/v1/seo/snapshot?trend_days=45', $this->adminApiHeaders())->json('data.trend');

        $this->assertCount(14, $trend);
    }

    public function test_no_data_at_all_is_an_empty_trend_not_a_row_of_zeros(): void
    {
        $data = $this->getJson('/api/admin/v1/seo/snapshot?trend_days=30', $this->adminApiHeaders())->json('data');

        $this->assertSame([], $data['trend']);
        $this->assertNull($data['trend_through']);
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

    public function test_share_of_voice_shows_only_the_latest_run_and_never_a_directory(): void
    {
        Cache::flush();
        $row = fn (array $o) => array_merge(['site_id' => null, 'is_us' => false, 'pos_1' => 1, 'pos_2_3' => 1, 'pos_4_10' => 1, 'pos_11_20' => 0, 'keywords_total' => 3, 'etv' => 10, 'is_new' => 0, 'is_lost' => 0, 'created_at' => now(), 'updated_at' => now()], $o);
        DB::table('seo_domain_overviews')->insert([
            $row(['domain' => 'gs.construction', 'is_us' => true, 'date' => '2026-09-06']),
            $row(['domain' => 'gs.construction', 'is_us' => true, 'date' => '2026-09-13']),
            // A competitor in both runs: shown, with a prior for the chevron.
            $row(['domain' => 'stillhere.com', 'date' => '2026-09-06', 'pos_1' => 2]),
            $row(['domain' => 'stillhere.com', 'date' => '2026-09-13', 'pos_1' => 4]),
            // Dropped from the weekly set after the first run: must not linger.
            $row(['domain' => 'longgone.com', 'date' => '2026-09-06']),
            // A directory that slipped into a run before the filter existed.
            $row(['domain' => 'houzz.com', 'date' => '2026-09-13', 'pos_1' => 900]),
        ]);

        $sov = collect($this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->assertOk()->json('data.dataforseo.share_of_voice'));

        $this->assertSame(['gs.construction', 'stillhere.com'], $sov->pluck('domain')->all());
        $this->assertSame(6, $sov->firstWhere('domain', 'stillhere.com')['top10']);
        $this->assertSame(4, $sov->firstWhere('domain', 'stillhere.com')['top10_prev']);
    }

    public function test_link_gap_hides_directories_and_says_so(): void
    {
        Cache::flush();
        $prospect = fn (array $o) => array_merge(['site_id' => null, 'rank' => 40, 'competitor_count' => 3, 'links_to_us' => false, 'spam_score' => null, 'links_to' => json_encode(['a.com' => 1, 'b.com' => 1, 'c.com' => 1]), 'created_at' => now(), 'updated_at' => now()], $o);
        DB::table('seo_backlink_prospects')->insert([
            $prospect(['domain' => 'localpaper.com']),
            $prospect(['domain' => 'yelp.com', 'rank' => 900]),
            $prospect(['domain' => 'pro.houzz.com', 'rank' => 880]),
        ]);

        $dfs = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->assertOk()->json('data.dataforseo');

        $this->assertSame(['localpaper.com'], array_column($dfs['link_gap'], 'domain'));
        $this->assertSame(2, $dfs['link_gap_hidden']['directory']);
        $this->assertSame(1, $dfs['link_gap_hidden']['visible']);
    }

    /** Three queries this week; one of them was also seen the week before. */
    private function seedQueryMetrics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19'));

        // This window (7 days to today).
        DB::table('gsc_query_metrics')->insert(['site_id' => null, 'date' => now()->subDays(1)->toDateString(), 'site_url' => 'sc-domain:example.test', 'query' => 'kitchen remodel', 'page' => 'https://example.test/kitchens', 'country' => 'usa', 'device' => 'MOBILE', 'impressions' => 100, 'clicks' => 10, 'position' => 4.0, 'ctr' => 0, 'dim_hash' => 'a1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('gsc_query_metrics')->insert(['site_id' => null, 'date' => now()->subDays(2)->toDateString(), 'site_url' => 'sc-domain:example.test', 'query' => 'kitchen remodel', 'page' => 'https://example.test/kitchens', 'country' => 'usa', 'device' => 'MOBILE', 'impressions' => 100, 'clicks' => 10, 'position' => 6.0, 'ctr' => 0, 'dim_hash' => 'a2', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('gsc_query_metrics')->insert(['site_id' => null, 'date' => now()->subDays(1)->toDateString(), 'site_url' => 'sc-domain:example.test', 'query' => 'bathroom remodel', 'page' => 'https://example.test/bathrooms', 'country' => 'usa', 'device' => 'MOBILE', 'impressions' => 50, 'clicks' => 5, 'position' => 9.0, 'ctr' => 0, 'dim_hash' => 'b1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('gsc_query_metrics')->insert(['site_id' => null, 'date' => now()->subDays(3)->toDateString(), 'site_url' => 'sc-domain:example.test', 'query' => 'basement finishing', 'page' => 'https://example.test/basements', 'country' => 'usa', 'device' => 'MOBILE', 'impressions' => 20, 'clicks' => 1, 'position' => 15.0, 'ctr' => 0, 'dim_hash' => 'c1', 'created_at' => now(), 'updated_at' => now()]);
        // The window before: only "kitchen remodel" existed, doing worse.
        DB::table('gsc_query_metrics')->insert(['site_id' => null, 'date' => now()->subDays(10)->toDateString(), 'site_url' => 'sc-domain:example.test', 'query' => 'kitchen remodel', 'page' => 'https://example.test/kitchens', 'country' => 'usa', 'device' => 'MOBILE', 'impressions' => 80, 'clicks' => 4, 'position' => 8.0, 'ctr' => 0, 'dim_hash' => 'p1', 'created_at' => now(), 'updated_at' => now()]);
        // Older than both windows: never counted.
        DB::table('gsc_query_metrics')->insert(['site_id' => null, 'date' => now()->subDays(30)->toDateString(), 'site_url' => 'sc-domain:example.test', 'query' => 'bathroom remodel', 'page' => 'https://example.test/bathrooms', 'country' => 'usa', 'device' => 'MOBILE', 'impressions' => 999, 'clicks' => 99, 'position' => 1.0, 'ctr' => 0, 'dim_hash' => 'z1', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_top_rows_page_through_every_query_sorted_with_the_prior_window_on_each_row(): void
    {
        $this->seedQueryMetrics();

        $response = $this->getJson('/api/admin/v1/seo/top-rows?dimension=query&days=7&sort=clicks&dir=desc&per_page=2', $this->adminApiHeaders())
            ->assertOk();

        $rows = $response->json('data');
        $meta = $response->json('meta');

        $this->assertSame(3, $meta['total']);
        $this->assertSame(2, $meta['last_page']);
        $this->assertSame(['kitchen remodel', 'bathroom remodel'], array_column($rows, 'query'));

        // Summed over the window, with the window before beside it.
        $this->assertSame(20, $rows[0]['clicks']);
        $this->assertSame(200, $rows[0]['impressions']);
        // round() hands back an int when the division is exact; the screen formats either.
        $this->assertEquals(10.0, $rows[0]['ctr']);
        $this->assertEquals(5.0, $rows[0]['position']);
        $this->assertEquals(['clicks' => 4, 'impressions' => 80, 'ctr' => 5.0, 'position' => 8.0], $rows[0]['prior']);
        // Nothing in the prior window: null, never a made-up zero.
        $this->assertNull($rows[1]['prior']);

        // The second page carries the rest.
        $page2 = $this->getJson('/api/admin/v1/seo/top-rows?dimension=query&days=7&sort=clicks&dir=desc&per_page=2&page=2', $this->adminApiHeaders())->json('data');
        $this->assertSame(['basement finishing'], array_column($page2, 'query'));

        // Sorting by position ascending puts the best-ranked first.
        $byPosition = $this->getJson('/api/admin/v1/seo/top-rows?dimension=query&days=7&sort=position&dir=asc', $this->adminApiHeaders())->json('data');
        $this->assertSame(['kitchen remodel', 'bathroom remodel', 'basement finishing'], array_column($byPosition, 'query'));

        // Pages are the same shape on the other dimension.
        $pages = $this->getJson('/api/admin/v1/seo/top-rows?dimension=page&days=7', $this->adminApiHeaders())->json('data');
        $this->assertSame('https://example.test/kitchens', $pages[0]['page']);
        $this->assertSame(4, $pages[0]['prior']['clicks']);

        // The snapshot's own top ten carries the prior window too.
        $snapshot = $this->getJson('/api/admin/v1/seo/snapshot?top_days=7', $this->adminApiHeaders())->json('data');
        $this->assertSame(4, $snapshot['top_queries'][0]['prior']['clicks']);
        $this->assertCount(3, $snapshot['top_queries']);
    }

    public function test_rankings_carry_bings_report_beside_googles(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19'));
        $row = fn (int $daysAgo, string $query, float $position, string $hash) => DB::table('bing_traffic_stats')->insert([
            'date' => now()->subDays($daysAgo)->toDateString(), 'site_url' => 'https://example.test/', 'query' => $query,
            'impressions' => 10, 'clicks' => 1, 'position' => $position, 'dim_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // This week: one query on page one's top, one on page one, one further down.
        $row(1, 'kitchen remodel', 2.0, 'b1');
        $row(3, 'kitchen remodel', 4.0, 'b2');   // averages to 3.0: still top 3
        $row(2, 'bathroom remodel', 8.0, 'b3');
        $row(2, 'basement finishing', 25.0, 'b4');
        // The week before: only one query, and it ranked worse.
        $row(9, 'kitchen remodel', 12.0, 'b5');
        // A row Bing reported without a position is not a ranking.
        $row(1, 'garage doors', 0.0, 'b6');

        $bing = $this->getJson('/api/admin/v1/seo/snapshot?rank_days=7', $this->adminApiHeaders())->assertOk()->json('data.rankings.bing');

        $this->assertSame('2026-09-18', $bing['as_of']);
        $this->assertSame(['tracked' => 3, 'top3' => 1, 'top10' => 2, 'top20' => 2, 'below20' => 1], $bing['current']);
        $this->assertSame(['tracked' => 1, 'top3' => 0, 'top10' => 0, 'top20' => 1, 'below20' => 0], $bing['prior']);
    }

    public function test_trouble_card_totals_carry_the_week_before_from_the_inspection_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00'));
        $state = fn (string $url, string $verdict, string $coverage) => GscCoverageState::create(['url' => $url, 'verdict' => $verdict, 'coverage_state' => $coverage, 'inspected_at' => now()]);
        $seen = fn (string $url, int $daysAgo, string $verdict, string $coverage) => GscCoverageStateHistory::create(['url' => $url, 'verdict' => $verdict, 'coverage_state' => $coverage, 'page_fetch_state' => 'SUCCESSFUL', 'observed_at' => now()->subDays($daysAgo)]);

        // Today: A and C indexed, B not.
        $state('https://example.test/a', 'PASS', 'Submitted and indexed');
        $state('https://example.test/b', 'NEUTRAL', 'Discovered - currently not indexed');
        $state('https://example.test/c', 'PASS', 'Submitted and indexed');
        // Ten days ago: A was the one not indexed, B was fine, C was not yet
        // on the board, and D has since been dropped — only A and B count.
        $seen('https://example.test/a', 10, 'NEUTRAL', 'Discovered - currently not indexed');
        $seen('https://example.test/a', 1, 'PASS', 'Submitted and indexed');
        $seen('https://example.test/b', 10, 'PASS', 'Submitted and indexed');
        $seen('https://example.test/c', 2, 'PASS', 'Submitted and indexed');
        $seen('https://example.test/d', 10, 'FAIL', 'Not found (404)');

        $errors = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->assertOk()->json('data.gsc_errors');

        $this->assertSame(['tracked' => 3, 'problem' => 1, 'pass' => 2, 'not_indexed' => 1], $errors['totals']);
        $this->assertSame(['tracked' => 2, 'problem' => 1, 'pass' => 1, 'not_indexed' => 1, 'as_of' => '2026-09-13'], $errors['totals_prev']);
    }

    /** @return array{score: int, date: string}|null */
    private function priorHealth(array $ledger): ?array
    {
        Storage::disk('local')->put(SeoStorage::path('reports/health-history.json'), json_encode($ledger));
        $method = new \ReflectionMethod(SeoReportController::class, 'priorHealth');

        return $method->invoke(app(SeoReportController::class));
    }

    public function test_the_prior_health_score_prefers_last_week_and_falls_back_to_the_oldest_day_the_ledger_has(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-09-20'));

        // A week-old entry wins, the closest to seven days when there are several.
        $this->assertSame(['score' => 70, 'date' => '2026-09-13'], $this->priorHealth([
            '2026-09-01' => 50, '2026-09-08' => 66, '2026-09-13' => 70, '2026-09-18' => 79, '2026-09-20' => 62,
        ]));

        // A ledger that only began on the 17th: the oldest day stands in, so
        // the card still moves — production the week it started recording.
        $this->assertSame(['score' => 79, 'date' => '2026-09-17'], $this->priorHealth([
            '2026-09-17' => 79, '2026-09-18' => 79, '2026-09-19' => 62, '2026-09-20' => 62,
        ]));

        // Today alone is no comparison at all.
        $this->assertNull($this->priorHealth(['2026-09-20' => 62]));
        $this->assertNull($this->priorHealth([]));
    }
}
