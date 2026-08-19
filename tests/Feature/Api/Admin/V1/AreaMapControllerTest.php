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

    public function test_resolve_town_reports_allowed_state(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'address' => ['city' => 'Arlington Heights', 'ISO3166-2-lvl4' => 'US-IL'],
            ], 200),
        ]);

        $data = $this->postJson('/api/admin/v1/areas-map/resolve-town', [
            'lat' => 42.08, 'lng' => -87.98,
        ], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame('Arlington Heights', $data['city']);
        $this->assertSame('IL', $data['state']);
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
}
