<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * GET /api/admin/v1/seo/snapshot's 'pulse' key — Site Pulse
 * (SsSystems\Platform\Pulse\SnapshotBuilder, kit 0.10.0), read from
 * SeoReportController::pulseSnapshot(). PinAdminApiTenant hardcodes this
 * API to the 'gsc' tenant, so every site_events row here is seeded under
 * gsc's site_id.
 *
 * gsc (like jpeterson) has no search feature, so AppServiceProvider binds
 * SnapshotBuilder with no 'search_event' — the kit's own contract is that
 * this OMITS searches/searched_cities/filters entirely rather than sending
 * them as zero, which is exactly what this test pins.
 */
class SeoSnapshotPulseTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    protected function seedEvent(int $siteId, string $event, string $vhash, ?string $path = '/some-page'): void
    {
        DB::table('site_events')->insert([
            'site_id' => $siteId,
            'event' => $event,
            'path' => $path,
            'meta' => null,
            'vhash' => $vhash,
            'city' => null,
            'mobile' => false,
            'created_at' => now()->subHours(2)->toDateTimeString(),
        ]);
    }

    public function test_the_pulse_key_is_present_with_the_search_free_shape(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();

        $this->seedEvent($gsc->id, 'page', 'v1', '/projects/kitchen');
        $this->seedEvent($gsc->id, 'page', 'v2', '/projects/kitchen');
        $this->seedEvent($gsc->id, 'page', 'v3', '/reviews');
        // Same vhash as v1's page view, so this also counts a "person" for
        // the gallery feature row.
        $this->seedEvent($gsc->id, 'gallery', 'v1', '/projects/kitchen');
        $this->seedEvent($gsc->id, 'before_after', 'v2', '/projects/kitchen');

        $pulse = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())
            ->assertOk()
            ->json('data.pulse');

        $this->assertNotNull($pulse);
        $this->assertSame('America/Chicago', $pulse['timezone']);
        $this->assertSame(7, $pulse['window_days']);
        $this->assertSame(14, $pulse['trend_days']);

        // Every key the kit's shape documents, always present.
        foreach (['mobile_share_pct', 'totals', 'totals_prev', 'days', 'features', 'hours', 'visitor_cities', 'top_pages', 'js_errors'] as $key) {
            $this->assertArrayHasKey($key, $pulse, "pulse.{$key} must always be present");
        }

        // gsc has no search feature: search_event stays null, so these are
        // OMITTED, not zeroed.
        $this->assertArrayNotHasKey('searches', $pulse['totals']);
        $this->assertArrayNotHasKey('searches', $pulse['totals_prev']);
        $this->assertArrayNotHasKey('searched_cities', $pulse);
        $this->assertArrayNotHasKey('filters', $pulse);
        foreach ($pulse['days'] as $day) {
            $this->assertArrayNotHasKey('searches', $day);
        }

        // Values, from the seeded rows: 3 distinct visitors, 3 page views.
        $this->assertSame(3, $pulse['totals']['visitors']);
        $this->assertSame(3, $pulse['totals']['page_views']);

        $features = collect($pulse['features'])->keyBy('key');
        $this->assertSame('Galleries opened', $features['gallery']['label']);
        $this->assertSame(1, $features['gallery']['uses']);
        $this->assertSame(1, $features['gallery']['people']);
        $this->assertSame('Before/after used', $features['before_after']['label']);
        $this->assertSame(1, $features['before_after']['uses']);

        $this->assertContains('/projects/kitchen', array_column($pulse['top_pages'], 'path'));
    }

    public function test_the_pulse_key_is_cached_for_five_minutes(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $this->seedEvent($gsc->id, 'page', 'v1');

        $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->assertOk();

        $cacheKey = Tenancy::for($gsc, fn () => Tenancy::cacheKey('admin.seo-reports.pulse'));
        $this->assertTrue(Cache::has($cacheKey));

        // A second page view arriving after the first snapshot must NOT show
        // up until the cache expires — same 5-minute contract as Dawn's.
        $this->seedEvent($gsc->id, 'page', 'v2');

        $pulse = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->json('data.pulse');
        $this->assertSame(1, $pulse['totals']['visitors'], 'the cached value must still be served');
    }
}
