<?php

namespace Tests\Feature\Areas;

use App\Http\Controllers\Api\Admin\V1\AreaMapController;
use App\Models\Town;
use App\Services\OpenStreetMapGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The coverage map's orange dots come from this query, and for a long time it
 * asked OSM for city|town|village only. OSM files small communities as
 * `hamlet`, so places the base map plainly labels — Lacota, Grand Junction,
 * Pullman — had no dot and no way to be added to the service areas, while
 * Bloomingdale and Breedsville right beside them did. One bounding box near
 * South Haven held 3 villages against 9 hamlets, so most of the small places
 * the studio serves were invisible to that screen.
 */
class TownGazetteerScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_the_gazetteer_query_includes_hamlets(): void
    {
        Http::fake(['*' => Http::response(['elements' => []])]);

        app(OpenStreetMapGeocoder::class)->townsInBounds(42.28, -86.25, 42.45, -85.95);

        Http::assertSent(function ($request) {
            $query = urldecode($request->body() ?: $request->url());

            $this->assertStringContainsString('hamlet', $query, 'hamlets are how OSM files most small towns');

            // The bigger categories must not have been traded away for it.
            foreach (['city', 'town', 'village'] as $kind) {
                $this->assertStringContainsString($kind, $query);
            }

            return true;
        });
    }

    /**
     * The site reports every town in view WITH its kind, and takes no view on
     * which of them deserve a dot — the central admin decides that, once, for
     * every tenant. This test exists to stop that policy creeping back into the
     * site, where it would have to be kept in step by hand.
     */
    public function test_the_site_reports_kind_and_does_not_decide_what_is_drawn(): void
    {
        Town::create(['name' => 'Bloomingdale', 'latitude' => 42.3792, 'longitude' => -85.9564, 'kind' => 'village']);
        Town::create(['name' => 'Lacota', 'latitude' => 42.4136, 'longitude' => -86.1298, 'kind' => 'hamlet']);

        $request = Request::create('/x', 'GET', [
            'south' => 42.2, 'west' => -86.3, 'north' => 42.5, 'east' => -85.8,
        ]);

        $towns = json_decode(
            app(AreaMapController::class)->candidates($request)->getContent(),
            true
        )['data']['towns'];

        $byName = collect($towns)->keyBy('name');

        // Both are reported — the hamlet included, because its label is the
        // only way it can ever be added.
        $this->assertSame('village', $byName['Bloomingdale']['kind']);
        $this->assertSame('hamlet', $byName['Lacota']['kind']);

        // And it is findable by position, which is what names it on a click.
        $this->assertSame('Lacota', Town::nearestTo(42.4136, -86.1298)?->name);
    }

    /** A click on open ground must resolve to nothing, or stray clicks become areas. */
    public function test_nothing_is_offered_far_from_any_town(): void
    {
        Town::create(['name' => 'Lacota', 'latitude' => 42.4136, 'longitude' => -86.1298, 'kind' => 'hamlet']);

        $this->assertNull(Town::nearestTo(42.2, -85.5));
    }

    /** The neighbourhood pass asks Overpass for a different place tag entirely. */
    public function test_a_kinds_override_changes_the_overpass_query(): void
    {
        Http::fake(['*' => Http::response(['elements' => []])]);

        app(OpenStreetMapGeocoder::class)->townsInBounds(41.8, -87.8, 42.0, -87.5, null, ['neighbourhood', 'suburb', 'quarter']);

        Http::assertSent(function ($request) {
            $query = urldecode($request->body() ?: $request->url());

            foreach (['neighbourhood', 'suburb', 'quarter'] as $kind) {
                $this->assertStringContainsString($kind, $query);
            }

            // The settlement kinds must not have leaked into this pass — a
            // neighbourhood query that also matched "town" would draw two
            // dots for the same place under two different policies.
            $this->assertStringNotContainsString('hamlet', $query);

            return true;
        });
    }

    /**
     * Two calls over the SAME viewport with different $kinds must reach
     * Overpass twice, not share one cache row — otherwise whichever pass ran
     * first (settlement or neighbourhood) would silently answer for both.
     */
    public function test_the_cache_key_is_scoped_by_kinds(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['elements' => [['tags' => ['name' => 'Chicago', 'place' => 'city'], 'lat' => 41.88, 'lon' => -87.63]]])
                ->push(['elements' => [['tags' => ['name' => 'Logan Square', 'place' => 'neighbourhood'], 'lat' => 41.92, 'lon' => -87.71]]]),
        ]);

        $geocoder = app(OpenStreetMapGeocoder::class);

        $settlements = $geocoder->townsInBounds(41.8, -87.8, 42.0, -87.5);
        $neighbourhoods = $geocoder->townsInBounds(41.8, -87.8, 42.0, -87.5, null, ['neighbourhood', 'suburb', 'quarter']);

        $this->assertSame('Chicago', $settlements[0]['name']);
        $this->assertSame('Logan Square', $neighbourhoods[0]['name']);
        Http::assertSentCount(2);

        // And re-asking either one now serves from its OWN cached row —
        // still only the two calls above.
        $geocoder->townsInBounds(41.8, -87.8, 42.0, -87.5);
        $geocoder->townsInBounds(41.8, -87.8, 42.0, -87.5, null, ['neighbourhood', 'suburb', 'quarter']);
        Http::assertSentCount(2);
    }
}
