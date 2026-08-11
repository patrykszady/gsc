<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The Yelp admin panel's buttons act on a live, self-healing system: a session
 * the server refreshes every 6 hours, a captcha budget measured in real money,
 * and a photo backlog that only re-queues on one specific state transition.
 *
 * Each test here pins a control that was quietly doing the opposite of what
 * the page promised.
 */
class YelpAdminPanelSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('services.yelp.business.email', 'owner@example.com');
        Config::set('services.yelp.business.password', 'secret');
    }

    private function panelSource(): string
    {
        return file_get_contents(base_path('app/Livewire/Admin/PlatformsSettings.php'));
    }

    public function test_manual_login_does_not_clear_the_dead_flag_before_recovery(): void
    {
        // markSessionFresh() re-queues the stalled photos ONLY when it sees
        // yelp.session_dead still set ($wasDead). Clearing it first made a
        // successful manual login return 0 and strand every stalled photo,
        // while the page told the operator they would re-upload themselves.
        $src = $this->panelSource();
        $start = strpos($src, 'function verifyYelpLogin');
        $this->assertNotFalse($start);

        $body = substr($src, $start, 1600);

        $this->assertStringNotContainsString(
            "Cache::forget('yelp.session_dead')",
            $body,
            'clearing session_dead here defeats the photo re-queue in markSessionFresh()',
        );
    }

    public function test_the_requeue_gate_still_depends_on_the_dead_flag(): void
    {
        // The above test is only meaningful while markSessionFresh() gates on
        // this flag. If that changes, the guard above must be re-examined.
        $svc = file_get_contents(base_path('app/Services/YelpBusinessService.php'));
        $start = strpos($svc, 'function markSessionFresh');

        $this->assertNotFalse($start);
        $this->assertStringContainsString(
            "\$wasDead = Cache::has('yelp.session_dead')",
            substr($svc, $start, 400),
        );
    }

    public function test_login_now_respects_the_captcha_spend_floor(): void
    {
        // Each attempt buys a 2captcha solve. The button used to delete the
        // 30-minute floor, so a frustrated operator clicking repeatedly paid
        // for a solve every time — including for failures no retry can fix.
        $src = $this->panelSource();
        $start = strpos($src, 'function yelpLoginNow');
        $this->assertNotFalse($start);

        $body = substr($src, $start, 1200);

        $this->assertStringNotContainsString(
            "Cache::forget('yelp.auto_login_attempted')",
            $body,
            'the button must not clear the captcha-spend floor',
        );
        $this->assertStringContainsString("Cache::has('yelp.auto_login_attempted')", $body);
    }

    public function test_the_session_button_repairs_rather_than_only_judging(): void
    {
        // checkSession() can call markSessionDead() (12h upload freeze + spend
        // or an owner email) and never writes cookies back — so as a button it
        // could only ever worsen state. keepSessionAlive() runs the same probe
        // and repairs the jar when authenticated.
        $src = $this->panelSource();
        $start = strpos($src, 'function checkYelpSession');
        $this->assertNotFalse($start);

        $body = substr($src, $start, 1400);

        $this->assertStringContainsString('keepSessionAlive()', $body);
        $this->assertStringNotContainsString('$yelp->checkSession()', $body);
    }
}
