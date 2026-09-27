<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\AnalyticsController;
use App\Models\Site;
use App\Models\TrackedEvent;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use SsSystems\Platform\Http\Admin\TrackedEventReader;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * AnalyticsController now reads TrackedEvent through the kit's
 * TrackedEventReader (a class-string over `App\Models\TrackedEvent::class`)
 * instead of a bare `TrackedEvent::query()` call in the controller itself —
 * the same two-Site isolation guard every gsc-scoped kit adoption requires
 * before shipping (RULES.md's tenancy guard). Tenant safety rides entirely
 * on TrackedEvent's own BelongsToSite global scope, not on anything the
 * reader does; this proves that seam actually holds through the new class,
 * at both the reader level and the HTTP endpoint every tenant's admin
 * calls.
 */
class AnalyticsTenantIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    private function otherSite(): Site
    {
        $site = Site::query()->firstOrCreate(['slug' => 'jpeterson'], [
            'name' => 'J. Peterson Design', 'theme' => 'jpeterson', 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com',
        ]);
        $site->forceFill(['is_active' => true, 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com'])->save();
        Site::forgetActive();

        return $site->fresh();
    }

    public function test_the_reader_never_returns_another_tenants_rows(): void
    {
        $other = $this->otherSite();

        TrackedEvent::create(['type' => TrackedEvent::TYPE_PHONE_CLICK, 'label' => 'gsc-row', 'page_path' => '/contact']);

        Tenancy::for($other, function () {
            TrackedEvent::create(['type' => TrackedEvent::TYPE_EMAIL_CLICK, 'label' => 'jpeterson-row', 'page_path' => '/about']);

            $reader = new TrackedEventReader(TrackedEvent::class);
            $rows = $reader->rowsSince(now()->subDays(7));

            $this->assertCount(1, $rows);
            $this->assertSame('jpeterson-row', $rows->first()['label']);
        });

        // Back on gsc: only gsc's own row is visible through the reader.
        $reader = new TrackedEventReader(TrackedEvent::class);
        $rows = $reader->rowsSince(now()->subDays(7));

        $this->assertCount(1, $rows);
        $this->assertSame('gsc-row', $rows->first()['label']);

        // Both rows are truly present underneath, just invisible cross-tenant.
        $this->assertSame(2, TrackedEvent::withoutGlobalScopes()->count());
    }

    public function test_the_http_endpoint_never_serves_another_tenants_events(): void
    {
        $other = $this->otherSite();

        TrackedEvent::create(['type' => TrackedEvent::TYPE_PHONE_CLICK, 'label' => 'gsc-row']);
        Tenancy::for($other, function () {
            TrackedEvent::create(['type' => TrackedEvent::TYPE_EMAIL_CLICK, 'label' => 'jpeterson-row']);
        });

        $data = $this->getJson('/api/admin/v1/analytics/events', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('gsc-row', $data[0]['label']);
    }

    public function test_the_summary_cache_key_carries_the_tenant(): void
    {
        // The admin API is pinned to the gsc tenant (PinAdminApiTenant), so
        // a second tenant cannot be exercised over HTTP here; the seam that
        // keeps one tenant's cached summary out of another's admin is the
        // key itself (gsc review, 2026-09-27) — pin it directly.
        $other = $this->otherSite();
        $key = fn () => (new \ReflectionMethod(AnalyticsController::class, 'analyticsCacheKey'))
            ->invoke(app(AnalyticsController::class), 'analytics-summary:28::1');

        $this->assertSame('analytics-summary:28::1', $key(), 'the default tenant keeps the bare key');
        $this->assertSame('jpeterson:analytics-summary:28::1', Tenancy::for($other, $key));
    }
}
