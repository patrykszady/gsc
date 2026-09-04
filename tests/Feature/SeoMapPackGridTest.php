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
        $item = fn (int $rank, string $title, int $reviews) => ['type' => 'maps_search', 'rank_absolute' => $rank, 'title' => $title, 'place_id' => 'p' . md5($title), 'url' => 'https://' . preg_replace('/\W/', '', strtolower($title)) . '.test/', 'rating' => ['value' => 5, 'votes_count' => $reviews], 'is_claimed' => true, 'category' => 'Bathroom remodeler'];
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
}
