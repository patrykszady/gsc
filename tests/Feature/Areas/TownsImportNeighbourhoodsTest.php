<?php

namespace Tests\Feature\Areas;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * towns:import used to draw one box per market for settlement-level places
 * only. Big cities carry hundreds of named subdivisions Overpass files
 * separately (Chicago alone: 218 place=neighbourhood, 77 place=suburb — its
 * 77 official community areas — 12 place=quarter), invisible to that query.
 * This pins the tighter second pass that fetches them.
 */
class TownsImportNeighbourhoodsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_the_import_issues_a_tighter_neighbourhood_query_per_market(): void
    {
        Http::fake(['*' => Http::response(['elements' => []])]);

        // jpeterson's markets.php is the only declared market in this repo's
        // config; --market scopes both passes to just Chicago so the request
        // count below is exact.
        $this->artisan('towns:import', ['--market' => 'chicago'])
            ->assertExitCode(0);

        $requests = Http::recorded();
        $this->assertCount(2, $requests, 'one settlement box and one neighbourhood box for a single market');

        $bodies = collect($requests)->map(fn ($pair) => urldecode($pair[0]->body()));

        $settlement = $bodies->first(fn ($q) => str_contains($q, 'hamlet'));
        $neighbourhood = $bodies->first(fn ($q) => str_contains($q, 'neighbourhood'));

        $this->assertNotNull($settlement, 'the settlement pass must still run');
        $this->assertNotNull($neighbourhood, 'a neighbourhood|suburb|quarter query must be issued');
        $this->assertStringContainsString('suburb', $neighbourhood);
        $this->assertStringContainsString('quarter', $neighbourhood);
        // The two passes ask for disjoint kinds — a settlement place must
        // never also be requested by the tighter query, or the same node
        // would be imported twice under two different kinds.
        $this->assertStringNotContainsString('hamlet', $neighbourhood);
        $this->assertStringNotContainsString('neighbourhood', $settlement);
    }

    public function test_the_neighbourhood_box_is_tighter_than_the_settlement_box(): void
    {
        Http::fake(['*' => Http::response(['elements' => []])]);

        $this->artisan('towns:import', ['--market' => 'chicago', '--radius' => '0.45', '--neighbourhood-radius' => '0.20'])
            ->assertExitCode(0);

        $bodies = collect(Http::recorded())->map(fn ($pair) => urldecode($pair[0]->body()));

        $settlement = $bodies->first(fn ($q) => str_contains($q, 'hamlet'));
        $neighbourhood = $bodies->first(fn ($q) => str_contains($q, 'neighbourhood'));

        // Overpass's bbox args appear as "(south,west,north,east)"; pull the
        // south value out of each query by NAME (not by sorting the pair,
        // which would prove nothing) to compare how wide a net each casts.
        $southOf = function (string $query): float {
            preg_match('/\(([\-\d.]+),/', $query, $m);

            return (float) $m[1];
        };

        // Chicago is at lat 41.8781. A 0.45° box reaches further south
        // (smaller latitude) than a 0.20° one.
        $this->assertLessThan($southOf($neighbourhood), $southOf($settlement));
    }

    public function test_a_hand_drawn_bbox_skips_the_neighbourhood_pass(): void
    {
        Http::fake(['*' => Http::response(['elements' => []])]);

        $this->artisan('towns:import', ['--bbox' => '41.5,-88.2,42.2,-87.5'])
            ->assertExitCode(0);

        // No "market" exists for a raw bbox to be tightened around, so only
        // the one box the caller drew is ever fetched.
        Http::assertSentCount(1);
    }

    public function test_neighbourhood_places_are_stored_with_their_kind(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['elements' => []]) // settlement pass: nothing new
                ->push(['elements' => [
                    ['tags' => ['name' => 'Logan Square', 'place' => 'neighbourhood'], 'lat' => 41.9294, 'lon' => -87.7073],
                ]]),
        ]);

        $this->artisan('towns:import', ['--market' => 'chicago'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('towns', ['name' => 'Logan Square', 'kind' => 'neighbourhood']);
    }
}
