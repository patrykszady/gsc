<?php

namespace Tests\Feature;

use App\Console\Commands\SeoMapPackGrid;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoMapPackGridTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_grid_geometry_is_centered_and_spans_the_radius(): void
    {
        $pts = SeoMapPackGrid::grid(42.1, -87.9, 3, 15);
        $this->assertCount(9, $pts);
        $this->assertSame([42.1, -87.9], $pts[4], 'the middle point is the center');
        $this->assertEqualsWithDelta(15 / 69, $pts[8][0] - $pts[4][0], 0.001, 'outer row is the radius away');
    }

    public function test_scan_records_rank_per_point_pack_share_and_competitors(): void
    {
        config(['services.dataforseo.login' => 'u', 'services.dataforseo.password' => 'p', 'brand.name' => 'GS Construction & Remodeling',
            'seo.map_pack' => ['center_lat' => 42.1, 'center_lng' => -87.9, 'grid_size' => 3, 'radius_miles' => 5, 'keywords' => ['bathroom remodeling']]]);
        $item = fn (int $rank, string $title, int $reviews) => ['type' => 'maps_search', 'rank_absolute' => $rank, 'title' => $title, 'place_id' => 'p'.md5($title), 'url' => 'https://'.preg_replace('/\W/', '', strtolower($title)).'.test/', 'rating' => ['value' => 5, 'votes_count' => $reviews], 'is_claimed' => true, 'category' => 'Bathroom remodeler'];
        $calls = 0;
        Http::fake(function ($request) use (&$calls, $item) {
            if (str_contains($request->url(), 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 20]]]]]]);
            }
            $calls++;
            // We appear at #4 on the first point only; Prism owns the pack everywhere.
            $items = [$item(1, 'Prism Kitchen & Bath', 53), $item(2, 'Dreamline Remodeling', 58), $item(3, 'YelloSquare', 29)];
            if ($calls === 1) {
                $items[] = $item(4, 'GS Construction & Remodeling', 20);
            }

            return Http::response(['tasks' => [['cost' => 0.002, 'status_code' => 20000, 'result' => [['items' => $items]]]]]);
        });

        $this->artisan('seo:map-pack-grid', ['--budget' => 1])->assertExitCode(0);

        $scan = DB::table('map_pack_scans')->where('keyword', 'bathroom remodeling')->first();
        $this->assertSame(9, $calls);
        $this->assertSame(0, (int) $scan->in_top3);
        $this->assertEquals(0.0, (float) $scan->solv);
        $this->assertEquals(4.0, (float) $scan->arp);
        $detail = json_decode($scan->raw, true)['detail'];
        $this->assertSame('dataforseo', $detail['source']);
        $this->assertCount(9, $detail['grid']);
        $this->assertSame(1, $detail['found']);
        $this->assertSame('Prism Kitchen & Bath', $detail['pack_leaders'][0]['business']);
        $this->assertSame(9, $detail['pack_leaders'][0]['appearances']);

        $prism = DB::table('map_pack_competitors')->where('name', 'Prism Kitchen & Bath')->first();
        $this->assertSame(9, (int) $prism->pack_points);
        $this->assertSame(53, (int) $prism->reviews);
        $this->assertNull(DB::table('map_pack_competitors')->where('name', 'like', 'GS Construction%')->first(), 'we are never our own competitor');
    }

    public function test_fails_closed_when_the_balance_check_itself_fails(): void
    {
        config(['services.dataforseo.login' => 'u', 'services.dataforseo.password' => 'p',
            'seo.map_pack' => ['center_lat' => 42.1, 'center_lng' => -87.9, 'grid_size' => 3, 'radius_miles' => 5, 'keywords' => ['bathroom remodeling']]]);
        Http::fake(['*/appendix/user_data' => Http::response('', 500)]);

        $this->artisan('seo:map-pack-grid', ['--budget' => 1])
            ->expectsOutputToContain('balance unknown')
            ->assertExitCode(1);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'maps/live'));
        $this->assertSame(0, DB::table('map_pack_scans')->count());
    }

    /**
     * A budget cutoff mid-grid used to `break 2` out of both loops before the
     * persist code below the inner loop ever ran, so the whole in-progress
     * keyword's points were discarded and every later keyword was skipped
     * with no record at all. The points collected before the cutoff must
     * survive, flagged partial, and no further keyword may be started.
     */
    public function test_a_budget_cutoff_mid_grid_persists_the_points_collected_so_far(): void
    {
        config(['services.dataforseo.login' => 'u', 'services.dataforseo.password' => 'p', 'brand.name' => 'GS Construction & Remodeling',
            'seo.map_pack' => ['center_lat' => 42.1, 'center_lng' => -87.9, 'grid_size' => 3, 'radius_miles' => 5, 'keywords' => ['bathroom remodeling', 'kitchen remodeling']]]);
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            if (str_contains($request->url(), 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 20]]]]]]);
            }
            $calls++;

            // 5x the $0.002/point the --budget precheck estimated, so the
            // upfront guard passes (9 pts × 2 kw × $0.002 = $0.036 ≤ $0.05)
            // but real spend outruns the estimate mid-grid, same as a live
            // account whose per-call cost drifts from the flat estimate.
            return Http::response(['tasks' => [['cost' => 0.01, 'status_code' => 20000, 'result' => [['items' => [
                ['type' => 'maps_search', 'rank_absolute' => 1, 'title' => 'Prism Kitchen & Bath', 'place_id' => 'p1', 'url' => 'https://prism.test/', 'rating' => ['value' => 5, 'votes_count' => 50], 'is_claimed' => true, 'category' => 'Bathroom remodeler'],
            ]]]]]]);
        });

        $this->artisan('seo:map-pack-grid', ['--budget' => 0.05])
            ->expectsOutputToContain('PARTIAL')
            ->assertExitCode(0);

        $this->assertSame(5, $calls, 'stops mid-grid (5 × $0.01 = $0.05) rather than finishing or aborting outright');

        $scan = DB::table('map_pack_scans')->where('keyword', 'bathroom remodeling')->first();
        $this->assertNotNull($scan, 'the in-progress keyword is persisted, not discarded');
        $detail = json_decode($scan->raw, true)['detail'];
        $this->assertTrue($detail['partial']);
        $this->assertSame(5, $detail['points_collected']);
        $this->assertSame(9, $detail['points_expected']);
        $this->assertCount(5, $detail['grid']);

        // The competitor seen on those 4 points is still recorded.
        $this->assertNotNull(DB::table('map_pack_competitors')->where('name', 'Prism Kitchen & Bath')->where('keyword', 'bathroom remodeling')->first());

        // Budget is gone: the second keyword is never started this run.
        $this->assertNull(DB::table('map_pack_scans')->where('keyword', 'kitchen remodeling')->first());
    }
}
