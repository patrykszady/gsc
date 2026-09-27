<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\TrackedEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * Management API for ss-systems' SiteAnalytics screen — see
 * App\Http\Controllers\Api\Admin\V1\AnalyticsController, ported onto the
 * kit's BuildsAnalyticsScreen (0.13.0). One window (`days`, default 28) and
 * one `type_filter` scope everything. Byte-identical to jpeterson-design's
 * own AnalyticsControllerTest — gsc had no dedicated test for this
 * controller before the kit port, so this is new coverage rather than a
 * port of an existing file.
 */
class AnalyticsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/admin/v1/analytics/summary')->assertStatus(401);
        $this->getJson('/api/admin/v1/analytics/events')->assertStatus(401);
    }

    public function test_events_returns_paginated_rows_shaped_for_the_admin_table(): void
    {
        TrackedEvent::create(['type' => TrackedEvent::TYPE_PHONE_CLICK, 'label' => '8470000000', 'page_path' => '/contact']);
        TrackedEvent::create(['type' => TrackedEvent::TYPE_EMAIL_CLICK, 'label' => 'jenn@gs.construction', 'page_path' => '/contact']);

        $response = $this->getJson('/api/admin/v1/analytics/events', $this->adminApiHeaders())
            ->assertOk();

        $data = $response->json('data');
        $meta = $response->json('meta');

        $this->assertCount(2, $data);
        $this->assertSame(2, $meta['total']);
        $this->assertArrayHasKey('type_label', $data[0]);
        $this->assertSame('Phone click', collect($data)->firstWhere('type', TrackedEvent::TYPE_PHONE_CLICK)['type_label']);
    }

    public function test_events_can_be_filtered_by_type(): void
    {
        TrackedEvent::create(['type' => TrackedEvent::TYPE_PHONE_CLICK]);
        TrackedEvent::create(['type' => TrackedEvent::TYPE_FORM_SUBMIT]);

        $data = $this->getJson('/api/admin/v1/analytics/events?type_filter=form_submit', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame(TrackedEvent::TYPE_FORM_SUBMIT, $data[0]['type']);
    }

    public function test_summary_reports_per_type_stats_top_pages_and_a_trend_series(): void
    {
        TrackedEvent::create(['type' => TrackedEvent::TYPE_PHONE_CLICK, 'page_path' => '/contact']);
        TrackedEvent::create(['type' => TrackedEvent::TYPE_PHONE_CLICK, 'page_path' => '/contact']);
        TrackedEvent::create(['type' => TrackedEvent::TYPE_EMAIL_CLICK, 'page_path' => '/about']);
        TrackedEvent::create(['type' => TrackedEvent::TYPE_FORM_SUBMIT, 'page_path' => '/contact']);
        TrackedEvent::create(['type' => TrackedEvent::TYPE_CTA_CLICK, 'page_path' => '/']);

        $data = $this->getJson('/api/admin/v1/analytics/summary', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['stats']['phone']);
        $this->assertSame(1, $data['stats']['email']);
        $this->assertSame(1, $data['stats']['form']);
        $this->assertSame(1, $data['stats']['cta']);
        $this->assertSame(5, $data['stats']['total']);
        $this->assertSame(0, $data['stats_prev']['total'], 'nothing happened in the 28 days before');
        $this->assertSame(3, $data['top_pages']['/contact']);
        $this->assertSame(28, $data['days'], 'the default window is four whole weeks');
        $this->assertCount(28, $data['trend']);
        $this->assertSame(2, end($data['trend'])['phone'], 'today is the last row');
    }

    public function test_the_window_scopes_the_rows_the_tiles_and_the_prior_window(): void
    {
        TrackedEvent::create(['type' => TrackedEvent::TYPE_PHONE_CLICK, 'page_path' => '/contact']);
        // Ten days back: outside a 7-day window, inside the 7 days before it.
        TrackedEvent::forceCreate(['type' => TrackedEvent::TYPE_PHONE_CLICK, 'page_path' => '/old', 'created_at' => now()->subDays(10)]);

        $this->getJson('/api/admin/v1/analytics/events?days=7', $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.page_path', '/contact');

        $data = $this->getJson('/api/admin/v1/analytics/summary?days=7', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(7, $data['days']);
        $this->assertSame(1, $data['stats']['phone']);
        $this->assertSame(1, $data['stats_prev']['phone']);
        $this->assertSame(['/contact' => 1], $data['top_pages']);
        $this->assertCount(7, $data['trend']);
    }

    public function test_the_type_filter_narrows_top_pages_but_not_the_tiles(): void
    {
        TrackedEvent::create(['type' => TrackedEvent::TYPE_PHONE_CLICK, 'page_path' => '/contact']);
        TrackedEvent::create(['type' => TrackedEvent::TYPE_CTA_CLICK, 'page_path' => '/']);

        $data = $this->getJson('/api/admin/v1/analytics/summary?type_filter=phone_click', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(['/contact' => 1], $data['top_pages']);
        $this->assertSame(1, $data['stats']['phone']);
        $this->assertSame(1, $data['stats']['cta']);
        $this->assertSame(1, end($data['trend'])['cta']);
    }

    public function test_summary_days_accepts_only_the_pickers_windows(): void
    {
        $data = $this->getJson('/api/admin/v1/analytics/summary?days=60', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');
        $this->assertCount(60, $data['trend']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['trend'][0]['day']);
        $this->assertSame(360, count($this->getJson('/api/admin/v1/analytics/summary?days=360', $this->adminApiHeaders())->json('data.trend')));

        foreach ([30, 999] as $days) {
            $data = $this->getJson("/api/admin/v1/analytics/summary?days={$days}", $this->adminApiHeaders())
                ->assertOk()
                ->json('data');
            $this->assertSame(28, $data['days']);
            $this->assertCount(28, $data['trend']);
        }
    }
}
