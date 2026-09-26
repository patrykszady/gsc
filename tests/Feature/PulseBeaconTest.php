<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use SsSystems\Platform\Pulse\Recorder;
use Tests\TestCase;

/**
 * POST /t — the Site Pulse beacon (SsSystems\Platform\Pulse\BeaconController,
 * kit 0.10.0), wired in routes/web.php (throttle:120,1) and exempted from
 * CSRF in bootstrap/app.php (navigator.sendBeacon cannot set that header).
 * A SEPARATE endpoint from /track (App\Http\Controllers\TrackEventController)
 * — see routes/web.php and AppServiceProvider for why both exist.
 */
class PulseBeaconTest extends TestCase
{
    public function test_a_whitelisted_event_is_recorded_against_the_current_tenant(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();

        $this->postJson('https://gs.construction/t', ['e' => 'gallery', 'm' => [], 'p' => '/projects/some-remodel'])
            ->assertNoContent();

        $row = DB::table('site_events')->sole();
        $this->assertSame('gallery', $row->event);
        $this->assertSame('/projects/some-remodel', $row->path);
        $this->assertSame($gsc->id, $row->site_id);
        $this->assertNotEmpty($row->vhash);
    }

    public function test_an_off_whitelist_event_is_silently_dropped(): void
    {
        $this->postJson('https://gs.construction/t', ['e' => 'not_a_real_event', 'm' => [], 'p' => '/'])
            ->assertNoContent();

        $this->assertSame(0, DB::table('site_events')->count());
    }

    public function test_the_beacon_answers_204_until_throttled(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->postJson('https://gs.construction/t', ['e' => 'page', 'm' => [], 'p' => '/'])
                ->assertNoContent();
        }

        // The 121st request in the same minute, from the same IP, is refused
        // by the route's own throttle:120,1 — never reaching BeaconController
        // (which never itself returns anything but 204).
        $this->postJson('https://gs.construction/t', ['e' => 'page', 'm' => [], 'p' => '/'])
            ->assertStatus(429);
    }

    /**
     * Modeled on TenancyIsolationTest: a row recorded while one tenant is
     * bound must not be visible when reading back as the other tenant, and
     * vice versa — the same site_id scoping DatabaseTableStorage applies to
     * both the Recorder's writes (AppServiceProvider's singleton) and any
     * tenant-scoped read of the table.
     */
    public function test_events_are_isolated_between_tenants(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jpeterson = Site::where('slug', 'jpeterson')->firstOrFail();

        Tenancy::for($gsc, function () {
            app(Recorder::class)->track('page', [], '/gsc-only', '127.0.0.1', 'Mozilla/5.0 Test', 'test-app-key');
        });

        Tenancy::for($jpeterson, function () {
            app(Recorder::class)->track('page', [], '/jp-only', '127.0.0.1', 'Mozilla/5.0 Test', 'test-app-key');
        });

        $gscPaths = Tenancy::for($gsc, fn () => Tenancy::table('site_events')->pluck('path')->all());
        $jpPaths = Tenancy::for($jpeterson, fn () => Tenancy::table('site_events')->pluck('path')->all());

        $this->assertSame(['/gsc-only'], $gscPaths, 'gsc must not see jpeterson\'s row');
        $this->assertSame(['/jp-only'], $jpPaths, 'jpeterson must not see gsc\'s row');
    }
}
