<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Server-side session keepalive — the replacement for "leave Chrome open on
 * your desktop with the Session Bridge extension installed".
 *
 * The dangerous failure mode is not "keepalive did not run". It is a keepalive
 * run that overwrites a WORKING cookie jar with a logged-out one, which would
 * take the whole upload pipeline down until someone noticed. So the export is
 * gated on landing at an authenticated URL, and these tests hold that gate
 * shut from both sides: the script side (only authed branches export) and the
 * PHP side (a plain probe never passes the flag at all).
 */
class YelpKeepSessionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('services.yelp.business.email', 'owner@example.com');
        Config::set('services.yelp.business.password', 'secret');
    }

    private function script(): string
    {
        return file_get_contents(base_path('scripts/yelp-login.mjs'));
    }

    public function test_the_export_only_ever_runs_on_an_authenticated_page(): void
    {
        $src = $this->script();

        // Every call site must sit inside an isAuthedUrl() branch. If someone
        // later exports before the auth check, a logged-out jar overwrites a
        // good one and every upload starts failing with session_expired.
        //
        // Checked by looking BACKWARD from each call to the nearest branch it
        // could belong to, rather than by scanning a window around the
        // logged-out returns — those sit a few lines after a legitimate authed
        // export, so proximity alone flags correct code.
        $offsets = [];
        $from = 0;
        while (($at = strpos($src, 'await exportCookiesTo(page', $from)) !== false) {
            $offsets[] = $at;
            $from = $at + 1;
        }

        $this->assertGreaterThanOrEqual(2, count($offsets), 'expected an export call on each authed branch');

        foreach ($offsets as $at) {
            $preceding = substr($src, max(0, $at - 400), min(400, $at));
            $guard = strrpos($preceding, 'isAuthedUrl');
            $deadEnd = max(
                (int) strrpos($preceding, "return 'anonymous'"),
                (int) strrpos($preceding, "return 'blocked'"),
            );

            $this->assertNotFalse($guard, 'an export runs without an isAuthedUrl guard above it');
            $this->assertGreaterThan(
                $deadEnd,
                $guard,
                'an export is reachable after a logged-out return — it would overwrite a good jar',
            );
        }
    }

    public function test_the_export_writes_atomically(): void
    {
        // The upload jobs read this file on every run; a half-written jar reads
        // as a dead session, so the write must be tmp-then-rename.
        $src = $this->script();
        $fn = substr($src, strpos($src, 'async function exportCookiesTo'), 1400);

        $this->assertStringContainsString('renameSync', $fn, 'jar write must be atomic');
        $this->assertStringContainsString('0o600', $fn, 'jar holds live session cookies; keep it owner-only');
    }

    public function test_the_export_keeps_only_yelp_cookies(): void
    {
        $src = $this->script();
        $fn = substr($src, strpos($src, 'async function exportCookiesTo'), 1400);

        // Suffix test, not str_contains — 'evil-yelp.com.attacker.net' must not
        // pass, the same rule YelpCookieJar::isYelpDomain() enforces server-side.
        $this->assertStringContainsString("endsWith('.yelp.com')", $fn);
        $this->assertStringContainsString("=== 'yelp.com'", $fn);
    }

    public function test_a_plain_session_check_does_not_export(): void
    {
        // checkSession() is called by the admin "Verify Login" button and the
        // daily probe. Neither should rewrite the jar — only the keepalive does.
        $ref = new \ReflectionMethod(\App\Services\YelpBusinessService::class, 'checkSession');
        $param = $ref->getParameters()[0] ?? null;

        $this->assertNotNull($param, 'checkSession should take an explicit export flag');
        $this->assertSame('exportCookies', $param->getName());
        $this->assertTrue($param->isDefaultValueAvailable());
        $this->assertFalse($param->getDefaultValue(), 'exporting must be opt-in');
    }

    public function test_keepalive_is_a_short_run_not_a_daemon(): void
    {
        // Chromium cannot share one user-data-dir between processes, and
        // yelp-run-locked.sh SIGKILLs whatever holds that profile when an
        // upload finishes. A long-lived browser would be killed by every
        // upload; the keepalive must stay a scheduled short run.
        $console = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString('yelp:keep-session', $console);
        $this->assertMatchesRegularExpression(
            '/yelp:keep-session.*?\R.*?withoutOverlapping/s',
            $console,
            'keepalive shares the Chromium profile with uploads and must not overlap itself',
        );
    }

    public function test_the_command_treats_a_blocked_probe_as_success(): void
    {
        // DataDome blocking the probe tells us nothing about the session.
        // Exiting non-zero there would page the operator over a bot-wall.
        $src = file_get_contents(base_path('app/Console/Commands/YelpKeepSession.php'));
        $pos = strpos($src, '$authed === null');

        $this->assertNotFalse($pos);
        $this->assertStringContainsString('SUCCESS', substr($src, $pos, 400));
    }
}
