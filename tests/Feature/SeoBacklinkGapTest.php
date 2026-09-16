<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoBacklinkGapTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dataforseo.login' => 'u', 'services.dataforseo.password' => 'p']);
        DB::table('map_pack_competitors')->insert([
            ['site_id' => null, 'place_id' => 'p1', 'keyword' => 'kitchen remodeling', 'name' => 'C1', 'host' => 'competitor1.test', 'pack_points' => 5, 'seen_points' => 9, 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => null, 'place_id' => 'p2', 'keyword' => 'kitchen remodeling', 'name' => 'C2', 'host' => 'competitor2.test', 'pack_points' => 3, 'seen_points' => 9, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * DataForSeoService::$lastError is set-only — it is never cleared back to
     * null — so the old per-item heuristic ("no error, or the error text is
     * unchanged from before this call, means success") read a SECOND call
     * that fails with the exact same error text as a success. Faking every
     * competitor call with an identical failure reproduces that: this must
     * fail closed with zero prospects, not exit 0 having written nothing.
     */
    public function test_two_consecutive_identical_failures_are_not_read_as_success(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 20]]]]]]);
            }
            if (str_contains($request->url(), 'referring_domains')) {
                $target = $request->data()[0]['target'] ?? null;
                if (in_array($target, ['competitor1.test', 'competitor2.test'], true)) {
                    // The exact same failure, twice in a row.
                    return Http::response(['tasks' => [['cost' => 0, 'status_code' => 40501, 'status_message' => 'Invalid Field: target.']]]);
                }

                return Http::response(['tasks' => [['cost' => 0.024, 'status_code' => 20000, 'result' => [['items' => []]]]]]);
            }

            return Http::response([], 500);
        });

        $this->artisan('seo:backlink-gap', ['--competitors' => 2, '--budget' => 1])
            ->expectsOutputToContain('Every competitor failed')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('seo_backlink_prospects')->count());
    }
}
