<?php

namespace Tests\Feature;

use App\Models\ClientError;
use App\Models\Site;
use App\Models\TrackedEvent;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * gsc is multi-tenant — both `TrackedEvent` and `ClientError` (App\Models\
 * Concerns\BelongsToSite) must keep their tenant scope after kit 0.13.0's
 * Telemetry port (`SsSystems\Platform\Telemetry\TrackEventIngest`/
 * `ClientErrorIngest`, replacing the bodies of `App\Http\Controllers\
 * TrackEventController`/`ClientErrorController` with calls into two new
 * kit contracts). Written BEFORE that port, per the consolidation plan's
 * own note that this is the one side of the port with real complexity and
 * currently no safety net (ss-platform-kit/docs/CONSOLIDATION-PLAN.md,
 * "Kit 0.14.0", Telemetry bullet).
 *
 * Real HTTP requests against each tenant's own host — `App\Http\
 * Middleware\ResolveSite` resolves the Site from the Host header (see its
 * own docblock) — so this exercises the full public `/track` and
 * `/client-error` routes end-to-end for two different tenants, not just
 * the model layer. `Tenancy::for()` is the standing pattern every other
 * BelongsToSite isolation test in this suite uses to read back under a
 * specific tenant's scope (see `GbpPostThemeTenancyIsolationTest`,
 * `CoverageStoreTenantIsolationTest`).
 */
class TelemetryTenantIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function jpeterson(): Site
    {
        $site = Site::query()->firstOrCreate(['slug' => 'jpeterson'], [
            'name' => 'J. Peterson Design', 'theme' => 'jpeterson', 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com',
        ]);
        $site->forceFill(['is_active' => true, 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com'])->save();
        Site::forgetActive();

        return $site->fresh();
    }

    public function test_tracked_events_land_on_the_right_tenant_and_are_invisible_to_the_other(): void
    {
        $jpeterson = $this->jpeterson();
        $gsc = Site::query()->where('slug', 'gsc')->firstOrFail();

        $this->postJson('http://gs.construction/track', [
            'type' => 'phone_click',
            'label' => 'gsc-only',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->postJson('http://jpeterson-design.com/track', [
            'type' => 'email_click',
            'label' => 'jp-only',
        ])->assertOk()->assertJson(['ok' => true]);

        $gscLabels = Tenancy::for($gsc, fn () => TrackedEvent::pluck('label')->all());
        $jpLabels = Tenancy::for($jpeterson, fn () => TrackedEvent::pluck('label')->all());

        $this->assertSame(['gsc-only'], $gscLabels, "gsc must not see jpeterson's event");
        $this->assertSame(['jp-only'], $jpLabels, "jpeterson must not see gsc's event");

        // Sanity: both rows really exist, correctly stamped, once the scope is dropped.
        $this->assertSame(2, TrackedEvent::withoutSiteScope()->count());
        $this->assertSame($gsc->id, TrackedEvent::withoutSiteScope()->where('label', 'gsc-only')->value('site_id'));
        $this->assertSame($jpeterson->id, TrackedEvent::withoutSiteScope()->where('label', 'jp-only')->value('site_id'));
    }

    public function test_client_errors_land_on_the_right_tenant_and_are_invisible_to_the_other(): void
    {
        $jpeterson = $this->jpeterson();
        $gsc = Site::query()->where('slug', 'gsc')->firstOrFail();

        $this->postJson('http://gs.construction/client-error', [
            'message' => 'gsc only error',
            'source' => 'app.js',
            'line' => 1,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->postJson('http://jpeterson-design.com/client-error', [
            'message' => 'jp only error',
            'source' => 'app.js',
            'line' => 2,
        ])->assertOk()->assertJson(['ok' => true]);

        $gscMessages = Tenancy::for($gsc, fn () => ClientError::pluck('message')->all());
        $jpMessages = Tenancy::for($jpeterson, fn () => ClientError::pluck('message')->all());

        $this->assertSame(['gsc only error'], $gscMessages, "gsc must not see jpeterson's error");
        $this->assertSame(['jp only error'], $jpMessages, "jpeterson must not see gsc's error");

        $this->assertSame(2, ClientError::withoutSiteScope()->count());
        $this->assertSame($gsc->id, ClientError::withoutSiteScope()->where('message', 'gsc only error')->value('site_id'));
        $this->assertSame($jpeterson->id, ClientError::withoutSiteScope()->where('message', 'jp only error')->value('site_id'));
    }

    public function test_a_repeat_client_error_upserts_only_within_its_own_tenant(): void
    {
        $jpeterson = $this->jpeterson();
        $gsc = Site::query()->where('slug', 'gsc')->firstOrFail();

        $payload = ['message' => 'shared message text', 'source' => 'app.js', 'line' => 10];

        // The same fingerprint-producing payload, posted to BOTH tenants —
        // must create two SEPARATE rows (one per site_id), never merge
        // into one shared occurrence count.
        $this->postJson('http://gs.construction/client-error', $payload)->assertOk();
        $this->postJson('http://gs.construction/client-error', $payload)->assertOk();
        $this->postJson('http://jpeterson-design.com/client-error', $payload)->assertOk();

        $this->assertSame(2, ClientError::withoutSiteScope()->where('message', 'shared message text')->count());

        $gscOccurrences = Tenancy::for($gsc, fn () => ClientError::where('message', 'shared message text')->value('occurrences'));
        $jpOccurrences = Tenancy::for($jpeterson, fn () => ClientError::where('message', 'shared message text')->value('occurrences'));

        $this->assertSame(2, $gscOccurrences, "gsc posted twice, so its own row must have 2 occurrences");
        $this->assertSame(1, $jpOccurrences, "jpeterson posted once and must not inherit gsc's occurrence count");
    }
}
