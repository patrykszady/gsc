<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\RunSeoChannelSyncJob;
use App\Models\AreaServed;
use App\Models\Town;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * gsc-only coverage-map endpoints (AreaMapController), ported from the
 * legacy AreaList Livewire's mapAreas/candidates/resolveTown/createFromMap.
 * Every OpenStreetMap call is faked — Nominatim/Overpass must never be hit
 * from the automated suite (see task safety note).
 */
class AreaMapControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    public function test_map_returns_areas_with_coordinates_and_maps_key(): void
    {
        AreaServed::create(['city' => 'Mapped Town', 'slug' => 'mapped-town', 'latitude' => 42.0, 'longitude' => -87.9]);
        AreaServed::create(['city' => 'No Coords', 'slug' => 'no-coords']);

        $data = $this->getJson('/api/admin/v1/areas-map', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['areas']);
        $this->assertSame('Mapped Town', $data['areas'][0]['city']);
        $this->assertArrayHasKey('maps_browser_key', $data);
        $this->assertArrayHasKey('allowed_states', $data);
    }

    public function test_candidates_rejects_a_viewport_wider_than_five_degrees(): void
    {
        $data = $this->getJson('/api/admin/v1/areas-map/candidates?south=30&west=-95&north=45&east=-80', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['too_wide']);
        $this->assertSame([], $data['towns']);
    }

    public function test_candidates_excludes_towns_already_service_areas(): void
    {
        AreaServed::create(['city' => 'Arlington Heights', 'slug' => 'arlington-heights']);
        Town::create(['name' => 'Arlington Heights', 'latitude' => 42.08, 'longitude' => -87.98]);
        Town::create(['name' => 'Palatine', 'latitude' => 42.11, 'longitude' => -88.03]);

        $data = $this->getJson('/api/admin/v1/areas-map/candidates?south=42.0&west=-88.1&north=42.2&east=-87.9', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $names = collect($data['towns'])->pluck('name')->all();
        $this->assertContains('Palatine', $names);
        $this->assertNotContains('Arlington Heights', $names);
    }

    /**
     * resolve-town answers from the GAZETTEER now, not from a reverse-geocode:
     * it backs clicking a place the coverage map labels but does not dot, and
     * the name has to be the one on the map — reverse-geocoding the "Riverside"
     * label returns "Lincoln Charter Township". Http::preventStrayRequests() is
     * the assertion that matters most: it fails if a network call comes back.
     */
    public function test_resolve_town_names_the_nearest_gazetteer_town(): void
    {
        Http::preventStrayRequests();

        Town::create(['name' => 'Arlington Heights', 'latitude' => 42.08, 'longitude' => -87.98, 'kind' => 'village']);

        $data = $this->postJson('/api/admin/v1/areas-map/resolve-town', [
            'lat' => 42.081, 'lng' => -87.981,
        ], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame('Arlington Heights', $data['city']);
        $this->assertSame('IL', $data['state']);
        $this->assertTrue($data['allowed']);
    }

    /** A hamlet gets no orange dot but must still be nameable — its label is the only way to add it. */
    public function test_resolve_town_names_a_hamlet_that_has_no_dot(): void
    {
        Http::preventStrayRequests();

        Town::create(['name' => 'Lacota', 'latitude' => 42.4136, 'longitude' => -86.1298, 'kind' => 'hamlet']);

        $data = $this->postJson('/api/admin/v1/areas-map/resolve-town', [
            'lat' => 42.4136, 'lng' => -86.1298,
        ], $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertSame('Lacota', $data['city']);
    }

    /** Nothing near the click means no offer at all — what stops a stray click becoming a service area. */
    public function test_resolve_town_declines_far_from_any_town(): void
    {
        Http::preventStrayRequests();

        Town::create(['name' => 'Arlington Heights', 'latitude' => 42.08, 'longitude' => -87.98, 'kind' => 'village']);

        $data = $this->postJson('/api/admin/v1/areas-map/resolve-town', [
            'lat' => 41.0, 'lng' => -85.0,
        ], $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertNull($data['city']);
        $this->assertFalse($data['allowed']);
    }

    /**
     * A neighbourhood-kind town gets no dot (candidates() no longer offers
     * one), but a click can still land on its gazetteer label — and it must
     * be refused the SAME way createFromMap refuses it, or the map would
     * say "yes, add this" right up until the add itself failed.
     */
    public function test_resolve_town_does_not_allow_a_neighbourhood_outside_any_major_city_market(): void
    {
        Http::preventStrayRequests();

        Town::create(['name' => 'Logan Square', 'latitude' => 41.9294, 'longitude' => -87.7073, 'kind' => 'neighbourhood']);

        $data = $this->postJson('/api/admin/v1/areas-map/resolve-town', [
            'lat' => 41.9294, 'lng' => -87.7073,
        ], $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertSame('Logan Square', $data['city']);
        $this->assertFalse($data['allowed']);
    }

    /** The same point, with Chicago configured as a major-city market — now allowed. */
    public function test_resolve_town_allows_a_neighbourhood_inside_a_configured_major_city_market(): void
    {
        Http::preventStrayRequests();
        config(['markets.list' => [
            ['slug' => 'chicago', 'city' => 'Chicago', 'state' => 'IL', 'lat' => 41.8781, 'lng' => -87.6298],
        ]]);

        Town::create(['name' => 'Logan Square', 'latitude' => 41.9294, 'longitude' => -87.7073, 'kind' => 'neighbourhood']);

        $data = $this->postJson('/api/admin/v1/areas-map/resolve-town', [
            'lat' => 41.9294, 'lng' => -87.7073,
        ], $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertSame('Logan Square', $data['city']);
        $this->assertTrue($data['allowed']);
    }

    public function test_create_from_map_creates_area_and_queues_content_generation(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'address' => ['city' => 'New Town', 'ISO3166-2-lvl4' => 'US-IL'],
            ], 200),
        ]);
        Queue::fake();

        $data = $this->postJson('/api/admin/v1/areas-map/from-map', [
            'city' => 'New Town', 'lat' => 42.05, 'lng' => -87.95,
        ], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['created']);
        $this->assertSame('New Town', $data['area']['name']);
        $this->assertDatabaseHas('areas_served', ['city' => 'New Town']);

        Queue::assertPushed(RunSeoChannelSyncJob::class, function ($job) {
            return $job->command === 'seo:generate-area-content';
        });
    }

    /**
     * A click on a candidate dot passes its OSM kind through so a big-city
     * subdivision (Chicago's Logan Square, added as a 'neighbourhood') is
     * distinguishable later from an ordinary town — see the
     * add_kind_to_areas_served_table migration.
     *
     * This site declares no markets.list of its own (unlike jpeterson's three
     * metros — see App\Support\Areas\MajorCityMarkets), so the market that
     * makes Logan Square "inside a major city" has to be configured here.
     */
    public function test_create_from_map_stores_the_candidates_kind(): void
    {
        // Chicago coordinates resolve their state from the bundled ZIP
        // gazetteer alone (see StateLocatorTest), so no Nominatim call is
        // expected here — faked anyway so a regression fails loudly here
        // rather than by reaching the real network.
        Http::fake();
        Queue::fake();
        config(['markets.list' => [
            ['slug' => 'chicago', 'city' => 'Chicago', 'state' => 'IL', 'lat' => 41.8781, 'lng' => -87.6298],
        ]]);

        $data = $this->postJson('/api/admin/v1/areas-map/from-map', [
            'city' => 'Logan Square', 'lat' => 41.9294, 'lng' => -87.7073, 'kind' => 'neighbourhood',
        ], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['created']);
        $this->assertSame('neighbourhood', $data['area']['kind']);
        $this->assertDatabaseHas('areas_served', ['city' => 'Logan Square', 'kind' => 'neighbourhood']);
    }

    /**
     * The owner's rule, straight from the coverage map: a neighbourhood is
     * only a real, addable place inside one of this site's major-city
     * markets. This site declares none by default, so EVERY neighbourhood
     * click is refused until one is configured — the honest consequence of
     * gsc having no markets.list of its own (see MajorCityMarkets' docblock).
     */
    public function test_create_from_map_refuses_a_neighbourhood_with_no_major_city_market_configured(): void
    {
        Http::fake();
        Queue::fake();

        $data = $this->postJson('/api/admin/v1/areas-map/from-map', [
            'city' => 'Logan Square', 'lat' => 41.9294, 'lng' => -87.7073, 'kind' => 'neighbourhood',
        ], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['created']);
        $this->assertStringContainsString('major-city market', $data['message']);
        $this->assertDatabaseMissing('areas_served', ['city' => 'Logan Square']);
        Queue::assertNothingPushed();
    }

    /** The same market is configured, but the click is nowhere near it — still refused. */
    public function test_create_from_map_refuses_a_neighbourhood_far_from_the_configured_market(): void
    {
        Http::fake();
        Queue::fake();
        config(['markets.list' => [
            ['slug' => 'chicago', 'city' => 'Chicago', 'state' => 'IL', 'lat' => 41.8781, 'lng' => -87.6298],
        ]]);

        // Rockford, IL — same state, comfortably outside Chicago's
        // neighbourhood-radius box.
        $data = $this->postJson('/api/admin/v1/areas-map/from-map', [
            'city' => 'Some Subdivision', 'lat' => 42.2711, 'lng' => -89.0940, 'kind' => 'suburb',
        ], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['created']);
        $this->assertStringContainsString('major-city market', $data['message']);
        $this->assertDatabaseMissing('areas_served', ['city' => 'Some Subdivision']);
    }

    /**
     * The restriction is about the KIND, not the place — an ordinary town at
     * the exact same point Logan Square was refused from must still go
     * through with no market configured at all.
     */
    public function test_create_from_map_allows_an_ordinary_kind_with_no_market_configured(): void
    {
        Http::fake();
        Queue::fake();

        $data = $this->postJson('/api/admin/v1/areas-map/from-map', [
            'city' => 'Logan Square Village', 'lat' => 41.9294, 'lng' => -87.7073, 'kind' => 'village',
        ], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['created']);
        $this->assertSame('village', $data['area']['kind']);
    }

    /** A town typed in or clicked on empty ground carries no OSM kind at all. */
    public function test_create_from_map_without_a_kind_stores_null(): void
    {
        Http::fake();
        Queue::fake();

        $data = $this->postJson('/api/admin/v1/areas-map/from-map', [
            'city' => 'Plain Town', 'lat' => 42.05, 'lng' => -87.95,
        ], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['created']);
        $this->assertNull($data['area']['kind']);
        $this->assertDatabaseHas('areas_served', ['city' => 'Plain Town', 'kind' => null]);
    }

    public function test_create_from_map_refuses_a_duplicate_town(): void
    {
        AreaServed::create(['city' => 'Existing Town', 'slug' => 'existing-town']);

        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'address' => ['city' => 'Existing Town', 'ISO3166-2-lvl4' => 'US-IL'],
            ], 200),
        ]);
        Queue::fake();

        $data = $this->postJson('/api/admin/v1/areas-map/from-map', [
            'city' => 'Existing Town', 'lat' => 42.05, 'lng' => -87.95,
        ], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['created']);
        $this->assertStringContainsString('already a service area', $data['message']);
        Queue::assertNothingPushed();
    }

    /**
     * The hand-typed create path accepted no `kind` at all, so a neighbourhood
     * added through it was filed as a plain town and skipped the major-city
     * rule the map click enforces. Found by review.
     */
    public function test_the_plain_create_path_refuses_a_neighbourhood_outside_a_major_city(): void
    {
        $this->postJson('/api/admin/v1/areas', [
            'name' => 'Somewhere', 'kind' => 'neighbourhood', 'latitude' => 39.78, 'longitude' => -89.65,
        ], $this->adminApiHeaders())->assertStatus(422)->assertJsonValidationErrors('kind');

        $this->assertDatabaseMissing('areas_served', ['city' => 'Somewhere']);
    }
}
