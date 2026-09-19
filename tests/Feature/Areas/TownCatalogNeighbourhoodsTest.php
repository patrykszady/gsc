<?php

namespace Tests\Feature\Areas;

use App\Models\AreaServed;
use App\Models\Town;
use App\Support\Areas\TownCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Neighbourhoods no longer get a dot on the coverage map (the admin decides
 * what is drawn — App\Http\Controllers\Api\Admin\V1\AreaMapController::candidates),
 * but the owner's rule was "searchable", not "gone": TownCatalog::candidates()
 * merges them in from the shared `towns` gazetteer, under the exact rule
 * AreaMapController::createFromMap enforces on the way in — typed query
 * only, inside one of this tenant's major-city markets, not already an
 * area. See App\Support\Areas\MajorCityMarkets.
 *
 * Search terms below use an invented name (no real US place is called
 * "Thornbramble" anything) so a match can only be the row this test itself
 * created — the bundled Census catalog is ~33,000 real places and a common
 * word like "logan" collides with dozens of them.
 */
class TownCatalogNeighbourhoodsTest extends TestCase
{
    use RefreshDatabase;

    private const CHICAGO = ['slug' => 'chicago', 'city' => 'Chicago', 'state' => 'IL', 'lat' => 41.8781, 'lng' => -87.6298];

    public function test_a_neighbourhood_is_findable_by_a_typed_search_inside_a_major_city_market(): void
    {
        config(['markets.list' => [self::CHICAGO]]);
        Town::create(['name' => 'Thornbramble Square', 'latitude' => 41.9294, 'longitude' => -87.7073, 'kind' => 'neighbourhood']);

        $results = TownCatalog::candidates('thornbramble');

        $this->assertCount(1, $results);
        $this->assertSame('Thornbramble Square', $results[0]['name']);
        $this->assertSame('neighbourhood', $results[0]['kind']);
        // "carrying kind and an obviously-a-neighbourhood label" — a bare
        // "Thornbramble Square" would read exactly like an ordinary
        // candidate town.
        $this->assertStringContainsString('Chicago', $results[0]['label']);
        $this->assertStringContainsString('neighbourhood', $results[0]['label']);
    }

    /** No search typed: the untyped browse ranks the home region's Census towns, never a neighbourhood — the same restraint the map's dots now apply. */
    public function test_a_neighbourhood_is_never_offered_without_a_typed_search(): void
    {
        config(['markets.list' => [self::CHICAGO]]);
        Town::create(['name' => 'Thornbramble Square', 'latitude' => 41.9294, 'longitude' => -87.7073, 'kind' => 'neighbourhood']);

        $results = TownCatalog::candidates(null, 500);

        $this->assertNotContains('Thornbramble Square', array_column($results, 'name'));
    }

    /**
     * The same rule createFromMap enforces on the way in: outside every
     * declared major-city market, a neighbourhood is not offered at all —
     * mirrors gsc having no markets.list of its own by default.
     */
    public function test_a_neighbourhood_outside_any_major_city_market_is_not_searchable(): void
    {
        // No markets.list configured at all.
        Town::create(['name' => 'Thornbramble Square', 'latitude' => 41.9294, 'longitude' => -87.7073, 'kind' => 'neighbourhood']);

        $this->assertNotContains('Thornbramble Square', array_column(TownCatalog::candidates('thornbramble'), 'name'));
    }

    public function test_a_neighbourhood_far_from_the_configured_market_is_not_searchable(): void
    {
        config(['markets.list' => [self::CHICAGO]]);
        // Rockford, IL — same state, well outside Chicago's neighbourhood box.
        Town::create(['name' => 'Thornbramble Corner', 'latitude' => 42.2711, 'longitude' => -89.0940, 'kind' => 'suburb']);

        $this->assertNotContains('Thornbramble Corner', array_column(TownCatalog::candidates('thornbramble'), 'name'));
    }

    /** A settlement kind (village/hamlet/city/town) is untouched — this merge only ever adds NEIGHBOURHOOD_KINDS rows. */
    public function test_an_ordinary_settlement_kind_town_is_not_pulled_in_by_this_merge(): void
    {
        config(['markets.list' => [self::CHICAGO]]);
        Town::create(['name' => 'Thornbramble Village', 'latitude' => 41.90, 'longitude' => -87.70, 'kind' => 'village']);

        $this->assertNotContains('Thornbramble Village', array_column(TownCatalog::candidates('thornbramble'), 'name'));
    }

    public function test_a_neighbourhood_already_an_area_is_excluded(): void
    {
        config(['markets.list' => [self::CHICAGO]]);
        Town::create(['name' => 'Thornbramble Square', 'latitude' => 41.9294, 'longitude' => -87.7073, 'kind' => 'neighbourhood']);
        AreaServed::create(['city' => 'Thornbramble Square', 'slug' => 'thornbramble-square']);

        $this->assertNotContains('Thornbramble Square', array_column(TownCatalog::candidates('thornbramble'), 'name'));
    }

    /** The overall $limit still has the final say, same as every other candidate. */
    public function test_neighbourhood_matches_are_capped_by_the_requested_limit(): void
    {
        config(['markets.list' => [self::CHICAGO]]);
        foreach (range(1, 5) as $i) {
            Town::create(['name' => "Thornbramble Nook {$i}", 'latitude' => 41.90 + $i * 0.001, 'longitude' => -87.70, 'kind' => 'neighbourhood']);
        }

        $results = TownCatalog::candidates('thornbramble nook', 3);

        $this->assertCount(3, $results);
    }
}
