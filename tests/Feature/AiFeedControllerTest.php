<?php

namespace Tests\Feature;

use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * BUG FIX pin (2026-09-27): `/ai-feed.json`'s `services` array used to be a
 * hardcoded 5-entry list typed straight into the controller — it now reads
 * the real, live `App\Models\Service` catalog (`GscServiceCatalog`), the
 * same table the admin's Services screen manages. These two rows are
 * neither of them among the old hardcoded 5 (kitchen-remodeling,
 * bathroom-remodeling, home-remodeling, basement-remodeling,
 * home-additions) — before the fix, neither could ever have appeared in
 * the feed no matter what the admin did.
 */
class AiFeedControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_feed_lists_the_real_service_catalog_not_a_hardcoded_array(): void
    {
        Service::create(['name' => 'Outdoor Living', 'sort_order' => 1, 'blurb' => 'Decks, patios and outdoor kitchens.']);
        Service::create(['name' => 'Custom Millwork', 'sort_order' => 2]);

        $data = $this->getJson('/ai-feed.json')->assertOk()->json();

        $this->assertSame(
            ['outdoor-living', 'custom-millwork'],
            array_column($data['services'], 'slug'),
        );
        $this->assertSame('Decks, patios and outdoor kitchens.', $data['services'][0]['description']);
        $this->assertSame(url('/services/outdoor-living'), $data['services'][0]['url']);
        // None of the formerly-hardcoded slugs survive when the admin hasn't created them.
        $this->assertNotContains('kitchen-remodeling', array_column($data['services'], 'slug'));
    }

    public function test_the_feed_reflects_a_service_renamed_in_the_admin(): void
    {
        $service = Service::create(['name' => 'Kitchen Remodeling', 'sort_order' => 1]);

        $this->getJson('/ai-feed.json')->assertOk()
            ->assertJsonFragment(['slug' => $service->slug, 'name' => 'Kitchen Remodeling']);

        $service->update(['name' => 'Kitchen Renovation']);
        Cache::forget('ai_feed_v1');

        $this->getJson('/ai-feed.json')->assertOk()
            ->assertJsonFragment(['slug' => $service->slug, 'name' => 'Kitchen Renovation']);
    }

    public function test_the_feed_still_serves_the_shared_envelope_and_business_facts(): void
    {
        $data = $this->getJson('/ai-feed.json')->assertOk()->json();

        $this->assertSame('GS Construction', $data['business']['name']);
        $this->assertSame('+1-224-735-4200', $data['business']['phone']);
        $this->assertArrayHasKey('rating', $data['business']);
        $this->assertArrayHasKey('$schema', $data);
        $this->assertArrayHasKey('service_area', $data);
        $this->assertSame([], $data['services']);
    }
}
