<?php

namespace Tests\Feature;

use App\Exceptions\YelpSessionExpiredException;
use App\Jobs\YelpAutoLogin;
use App\Mail\YelpSessionExpired;
use App\Services\YelpBusinessService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A dead Yelp session must be able to bring itself back, and say so when it
 * cannot.
 *
 * Production, early October → 2026-11-12: the session died and every
 * automatic sign-in "crashed" in the second it started —
 *
 *   Yelp: headless login starting {"has_proxy":true,"has_twocaptcha":true,…}
 *   Yelp: session dead, aborting job without launching Chromium {"operation":"headless_login"}
 *   Yelp: headless login crashed {"error":"Yelp session is not authenticated. …"}
 *
 * — because the login ran under the same dead-session fail-fast that
 * protects uploads and the lead read. "crashed" pages nobody, the alert the
 * owner should have had was never reached (and would have been switched off
 * and misaddressed if it had been), so no Yelp lead arrived for five weeks
 * and nobody was told.
 *
 * No test here launches a browser: the sign-in script is stood in for by a
 * subclass, or by a shell script that only prints what the real one would.
 */
class YelpDeadSessionRevivalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        Queue::fake();

        Config::set('services.yelp.business.email', 'owner@example.com');
        Config::set('services.yelp.business.password', 'secret');
        Config::set('services.yelp.business.alert_emails', true);
        Config::set('mail.from.address', 'noreply@example.com');
        Config::set('mail.review_alert_to', 'owner@example.com');
        // Unattended sign-in is viable, as in production.
        Config::set('services.yelp.business.proxy', 'http://user:pass@proxy.test:8000');
        Config::set('services.twocaptcha.api_key', 'captcha-key');
        Config::set('services.anticaptcha.api_key', null);
        // Belt and braces: nothing below should reach a subprocess, and if
        // something ever does it must not be node.
        Config::set('services.yelp.business.node_binary', '/bin/false');
        Config::set('services.yelp.business.min_interval_seconds', 0);
        Config::set('services.yelp.business.user_data_dir', $this->scratch('profile'));
        // Never the real jar.
        Config::set('services.yelp.business.cookies_file', $this->scratch('yelp-cookies.json'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch());
        parent::tearDown();
    }

    private function scratch(string $path = ''): string
    {
        return storage_path('framework/testing/yelp-revival-'.getmypid().($path !== '' ? '/'.$path : ''));
    }

    private function markDead(): void
    {
        Cache::put('yelp.session_dead', ['at' => now()->toIso8601String(), 'note' => 'test'], now()->addHours(12));
    }

    /**
     * The service with the browser replaced by a canned script result.
     *
     * @param  array<string, mixed>|null  $payload  what scripts/yelp-login.mjs would print
     */
    private function serviceWithLoginScript(?array $payload): YelpBusinessService
    {
        $service = new class extends YelpBusinessService
        {
            public ?array $scriptPayload = null;

            public int $scriptRuns = 0;

            /** @var list<string> */
            public array $scriptArgs = [];

            /** What the script would do to the disk while it runs. */
            public ?\Closure $whileRunning = null;

            protected function runHeadlessLoginScript(array $args, array $cfg): array
            {
                $this->scriptRuns++;
                $this->scriptArgs = $args;

                if ($this->whileRunning) {
                    ($this->whileRunning)();
                }

                return ['payload' => $this->scriptPayload, 'stderr' => ''];
            }

            public function runUnderLock(string $operation, callable $callback): mixed
            {
                return $this->withAutomationLock($operation, $callback);
            }
        };

        $service->scriptPayload = $payload;
        $this->app->instance(YelpBusinessService::class, $service);

        return $service;
    }

    // ---- The guard ---------------------------------------------------------

    public function test_a_dead_session_does_not_abort_the_sign_in_meant_to_revive_it(): void
    {
        $this->markDead();
        $service = $this->serviceWithLoginScript(['ok' => true, 'authenticated' => true]);

        $result = $service->loginHeadless();

        $this->assertSame(1, $service->scriptRuns, 'the sign-in never reached its script');
        $this->assertTrue($result['ok'], 'code: '.$result['code'].' — '.$result['hint']);
        $this->assertFalse(Cache::has('yelp.session_dead'), 'a sign-in that worked must lift the dead flag');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function operationsThatUseTheSession(): array
    {
        return [
            'business photo upload' => ['business_photos_upload'],
            'portfolio upload' => ['portfolio_upload'],
            'lead read' => ['fetch_leads'],
            // Anything new is guarded until someone decides otherwise.
            'an operation added later' => ['something_new'],
        ];
    }

    #[DataProvider('operationsThatUseTheSession')]
    public function test_jobs_that_use_the_session_still_fail_fast_while_it_is_dead(string $operation): void
    {
        $this->markDead();
        $service = $this->serviceWithLoginScript(null);
        $ran = false;

        try {
            $service->runUnderLock($operation, function () use (&$ran): void {
                $ran = true;
            });
            $this->fail("{$operation} ran against a dead session");
        } catch (YelpSessionExpiredException) {
            $this->assertFalse($ran);
        }
    }

    public function test_the_lead_read_is_refused_before_any_browser_while_the_session_is_dead(): void
    {
        $this->markDead();
        Config::set('services.yelp.business.biz_id', 'biz-test');

        $this->expectException(YelpSessionExpiredException::class);

        app(YelpBusinessService::class)->fetchLeads([], $this->scratch('leads'));
    }

    public function test_only_the_sign_in_is_exempt(): void
    {
        $this->markDead();
        $service = $this->serviceWithLoginScript(null);

        $this->assertSame('ran', $service->runUnderLock('headless_login', fn (): string => 'ran'));
    }

    // ---- The real wrapper, with a stand-in for node ------------------------

    public function test_a_sign_in_that_worked_is_not_reported_as_crashed_when_the_wrapper_sweeps_itself(): void
    {
        // scripts/yelp-run-locked.sh ends with `pkill -KILL -f
        // "user-data-dir=<profile>"`. The login's own command line carries
        // that string, so the wrapper kills itself after the script has
        // printed its verdict and Symfony reports signal 9.
        File::ensureDirectoryExists($this->scratch());
        $node = $this->scratch('fake-node.sh');
        File::put($node, "#!/usr/bin/env bash\necho '{\"ok\":true,\"authenticated\":true,\"closed\":true}'\nexit 0\n");
        chmod($node, 0755);
        Config::set('services.yelp.business.node_binary', $node);

        // Never start a virtual display from a test, and keep this run's
        // flock off the one a real automation on this machine would hold.
        $display = getenv('DISPLAY');
        putenv('DISPLAY=:0');
        putenv('YELP_RUN_LOCK_FILE='.$this->scratch('lock'));
        $this->markDead();

        try {
            $result = app(YelpBusinessService::class)->loginHeadless();
        } finally {
            putenv($display === false ? 'DISPLAY' : 'DISPLAY='.$display);
            putenv('YELP_RUN_LOCK_FILE');
        }

        $this->assertTrue($result['ok'], 'code: '.$result['code'].' — '.$result['hint']);
        $this->assertFalse(Cache::has('yelp.session_dead'));
    }

    // ---- The cookie jar ------------------------------------------------------

    private function jar(): string
    {
        return (string) config('services.yelp.business.cookies_file');
    }

    private function writeJarFromBeforeTheSignIn(): void
    {
        File::ensureDirectoryExists(dirname($this->jar()));
        File::put($this->jar(), '{"cookies":[{"name":"bse","value":"dead","domain":".yelp.com"}]}');
        touch($this->jar(), time() - 3600);
    }

    public function test_the_sign_in_is_told_to_write_the_new_session_to_the_jar_every_other_run_injects(): void
    {
        $this->markDead();
        $service = $this->serviceWithLoginScript(['ok' => true, 'authenticated' => true]);

        $service->loginHeadless();

        $this->assertContains('--export-cookies='.$this->jar(), $service->scriptArgs);
    }

    public function test_the_script_writes_the_jar_only_from_the_authenticated_page_it_signed_in_on(): void
    {
        $source = (string) file_get_contents(base_path('scripts/yelp-login.mjs'));
        $login = substr($source, (int) strpos($source, 'async function modeLogin'));

        $detected = strpos($login, 'authenticated url detected');
        $export = strpos($login, 'await exportCookiesTo(authedPage, args.exportCookies)');
        $verdict = strpos($login, 'finish({ closed: false, authenticated: true })');

        $this->assertNotFalse($export, 'a sign-in that worked must rewrite the jar');
        $this->assertGreaterThan($detected, $export);
        $this->assertLessThan($verdict, $export, 'the jar must be written before the browser is closed');
        $this->assertStringContainsString(
            'if (args.exportCookies && isAuthedUrl(authedUrl))',
            substr($login, $export - 120, 120),
            'the jar may only be written from a confirmed-authenticated page',
        );
    }

    public function test_the_operators_sign_in_window_rewrites_the_jar_too(): void
    {
        $this->assertStringContainsString(
            "'--export-cookies=' . escapeshellarg(\\App\\Support\\YelpCookieJar::path())",
            (string) file_get_contents(app_path('Services/YelpRemoteLoginService.php')),
        );
    }

    public function test_a_jar_the_sign_in_did_not_rewrite_is_set_aside_so_it_cannot_sign_the_profile_back_out(): void
    {
        $this->markDead();
        $this->writeJarFromBeforeTheSignIn();
        $service = $this->serviceWithLoginScript(['ok' => true, 'authenticated' => true]);

        $this->assertTrue($service->loginHeadless()['ok']);

        $this->assertFileDoesNotExist($this->jar(), 'the dead session\'s jar is still where the next run would inject it from');
        $this->assertFileExists($this->jar().'.stale', 'set aside, not deleted');
    }

    public function test_a_jar_the_sign_in_rewrote_is_kept(): void
    {
        $this->markDead();
        $this->writeJarFromBeforeTheSignIn();
        $service = $this->serviceWithLoginScript(['ok' => true, 'authenticated' => true]);
        $service->whileRunning = fn () => File::put($this->jar(), '{"cookies":[{"name":"bse","value":"fresh","domain":".yelp.com"}]}');

        $this->assertTrue($service->loginHeadless()['ok']);

        $this->assertStringContainsString('fresh', File::get($this->jar()));
        $this->assertFileDoesNotExist($this->jar().'.stale');
    }

    public function test_a_sign_in_that_failed_leaves_the_jar_alone(): void
    {
        $this->markDead();
        $this->writeJarFromBeforeTheSignIn();
        $service = $this->serviceWithLoginScript(['ok' => true, 'authenticated' => false, 'code' => 'datadome_blocked', 'hint' => 'blocked']);

        $this->assertFalse($service->loginHeadless()['ok']);

        $this->assertFileExists($this->jar());
        $this->assertTrue(Cache::has('yelp.session_dead'));
    }

    // ---- The queued job and the command ------------------------------------

    public function test_the_queued_sign_in_runs_while_the_session_is_dead_and_records_what_happened(): void
    {
        $this->markDead();
        $service = $this->serviceWithLoginScript(['ok' => true, 'authenticated' => true]);

        (new YelpAutoLogin)->handle($service);

        $this->assertSame(1, $service->scriptRuns);
        $this->assertTrue(Cache::get('yelp.auto_login_result')['ok']);
        $this->assertFalse(Cache::has('yelp.session_dead'));
        Mail::assertNothingSent();
    }

    public function test_yelp_login_exits_zero_when_it_signs_in(): void
    {
        $this->markDead();
        $this->serviceWithLoginScript(['ok' => true, 'authenticated' => true]);

        $this->artisan('yelp:login')->assertExitCode(0);

        $this->assertFalse(Cache::has('yelp.session_dead'));
        $this->assertTrue(Cache::get('yelp.auto_login_result')['ok']);
    }

    public function test_yelp_login_that_fails_says_why_on_its_last_line_and_tells_the_owner_when_only_a_person_can_fix_it(): void
    {
        $this->markDead();
        $this->serviceWithLoginScript([
            'ok' => true,
            'authenticated' => false,
            'code' => 'needs_verification',
            'hint' => 'Yelp is asking for an emailed/SMS verification code.',
        ]);

        $this->artisan('yelp:login')
            ->expectsOutputToContain('Yelp sign-in failed (needs_verification): Yelp is asking for an emailed/SMS verification code.')
            ->assertExitCode(1);

        // Until now only the queued job stored the result or emailed: the
        // scheduled command met a verification prompt and told nobody.
        $this->assertSame('needs_verification', Cache::get('yelp.auto_login_result')['code']);
        $this->assertTrue(Cache::has('yelp.auto_login_attempted'), 'the command must hold the same 30-minute floor as the job');
        Mail::assertSent(YelpSessionExpired::class, fn (YelpSessionExpired $mail) => $mail->hasTo('owner@example.com')
            && str_contains((string) $mail->note, 'needs_verification'));
    }

    public function test_the_nightly_check_exits_one_when_signed_out_and_says_what_happens_next(): void
    {
        $this->partialMock(YelpBusinessService::class, function ($mock): void {
            $mock->shouldReceive('checkSession')->once()->andReturn(false);
        });

        $this->artisan('yelp:check-session')
            ->expectsOutputToContain('NOT authenticated — automatic sign-in has been queued')
            ->assertExitCode(1);
    }

    public function test_the_nightly_check_asks_for_a_person_when_no_automatic_sign_in_can_run(): void
    {
        Config::set('services.yelp.business.proxy', null);
        $this->partialMock(YelpBusinessService::class, function ($mock): void {
            $mock->shouldReceive('checkSession')->once()->andReturn(false);
        });

        $this->artisan('yelp:check-session')
            ->expectsOutputToContain('sign in from Admin → Platforms (Verify Login)')
            ->assertExitCode(1);
    }

    public function test_the_nightly_check_is_not_a_failure_when_signed_in_or_when_it_could_not_tell(): void
    {
        $this->partialMock(YelpBusinessService::class, function ($mock): void {
            $mock->shouldReceive('checkSession')->twice()->andReturn(true, null);
        });

        $this->artisan('yelp:check-session')->assertExitCode(0);
        $this->artisan('yelp:check-session')->assertExitCode(0);
    }

    // ---- When only a person can fix it -------------------------------------

    public function test_a_verification_prompt_stops_further_automatic_attempts_until_a_person_acts(): void
    {
        $service = app(YelpBusinessService::class);

        $service->recordAutoLoginResult(['ok' => false, 'code' => 'needs_verification', 'hint' => 'Yelp wants a code.']);

        $this->assertNotNull($service->autoLoginPaused());
        Mail::assertSent(YelpSessionExpired::class, 1);

        // The next failed check must not buy another captcha solve.
        $service->markSessionDead('checkSession reported not authenticated');
        Queue::assertNotPushed(YelpAutoLogin::class);

        // Someone signs in (Verify Login): everything is lifted.
        $service->markSessionFresh();
        $this->assertNull($service->autoLoginPaused());

        $service->markSessionDead('a later death');
        Queue::assertPushed(YelpAutoLogin::class, 1);
    }

    public function test_a_rejected_password_stops_attempts_until_the_stored_login_changes(): void
    {
        $service = app(YelpBusinessService::class);

        $service->recordAutoLoginResult(['ok' => false, 'code' => 'bad_credentials', 'hint' => 'Yelp rejected the email/password.']);
        $service->markSessionDead('still dead');
        Queue::assertNotPushed(YelpAutoLogin::class);

        Config::set('services.yelp.business.password', 'a-new-password');

        $this->assertNull($service->autoLoginPaused());
        $service->markSessionDead('still dead');
        Queue::assertPushed(YelpAutoLogin::class, 1);
    }

    public function test_a_failure_the_next_run_may_clear_neither_pauses_nor_pages(): void
    {
        $service = app(YelpBusinessService::class);

        $service->recordAutoLoginResult(['ok' => false, 'code' => 'crashed', 'hint' => 'boom']);
        $service->recordAutoLoginResult(['ok' => false, 'code' => 'datadome_blocked', 'hint' => 'blocked']);

        $this->assertNull($service->autoLoginPaused());
        Mail::assertNothingSent();
    }

    // ---- A day's allowance ---------------------------------------------------

    public function test_a_sign_in_that_works_but_does_not_stick_is_not_repeated_all_day(): void
    {
        $service = app(YelpBusinessService::class);

        // Signed in, found signed out again by the next lead read, signed in
        // again… each round a proxy session and paid captcha solves.
        for ($round = 1; $round <= YelpBusinessService::AUTO_LOGIN_DAILY_LIMIT; $round++) {
            $service->markSessionDead('yelp-fetch-leads reported not authenticated');
            Queue::assertPushed(YelpAutoLogin::class, $round);

            $service->recordAutoLoginResult(['ok' => true, 'code' => 'authenticated', 'hint' => 'Logged in.']);
            $service->markSessionFresh();
            $this->travel(31)->minutes();
        }

        $service->markSessionDead('yelp-fetch-leads reported not authenticated');

        Queue::assertPushed(YelpAutoLogin::class, YelpBusinessService::AUTO_LOGIN_DAILY_LIMIT);
        $this->assertTrue($service->autoLoginBudgetSpent());
        Mail::assertSent(YelpSessionExpired::class, fn (YelpSessionExpired $mail) => str_contains((string) $mail->note, '4 attempts in the last 24 hours')
            && str_contains((string) $mail->note, 'signed in, but the session did not stay signed in'));

        // A day on, the allowance is back.
        $this->travel(24)->hours();
        $service->markSessionDead('still dead');

        Queue::assertPushed(YelpAutoLogin::class, YelpBusinessService::AUTO_LOGIN_DAILY_LIMIT + 1);
    }

    public function test_an_attempt_that_never_started_a_browser_does_not_use_the_allowance(): void
    {
        $service = app(YelpBusinessService::class);

        foreach (range(1, 6) as $ignored) {
            $service->recordAutoLoginResult(['ok' => false, 'code' => 'busy', 'hint' => 'Another Yelp automation is running; try again shortly.']);
        }

        $this->assertSame(0, $service->autoLoginAttemptsToday());
        $this->assertFalse($service->autoLoginBudgetSpent());
    }

    // ---- The alert ----------------------------------------------------------

    public function test_the_owner_is_told_when_hours_of_automatic_sign_ins_have_not_brought_it_back(): void
    {
        $service = app(YelpBusinessService::class);

        // 02:15 — first seen dead: a sign-in is queued and nobody is paged.
        $this->travelTo(now()->setTime(2, 15));
        $service->markSessionDead('checkSession reported not authenticated');
        Queue::assertPushed(YelpAutoLogin::class, 1);
        Mail::assertNothingSent();

        // ...which fails for a reason no one is paged for on its own.
        $service->recordAutoLoginResult(['ok' => false, 'code' => 'crashed', 'hint' => 'The login script died.']);
        Mail::assertNothingSent();

        // 06:20 keep-alive — still dead four hours on. This is the email that
        // never came in five weeks.
        $this->travel(245)->minutes();
        $service->markSessionDead('checkSession reported not authenticated');

        Queue::assertPushed(YelpAutoLogin::class, 2);
        Mail::assertSent(YelpSessionExpired::class, 1);
        Mail::assertSent(YelpSessionExpired::class, fn (YelpSessionExpired $mail) => str_contains((string) $mail->note, 'automatic sign-in has not brought it back')
            && str_contains((string) $mail->note, '"crashed": The login script died.'));
    }

    public function test_the_alert_repeats_once_a_day_while_the_session_stays_dead(): void
    {
        // No proxy: the plain "a person has to sign in" path.
        Config::set('services.yelp.business.proxy', null);
        $service = app(YelpBusinessService::class);

        $this->travelTo(now()->setTime(2, 15));
        $service->markSessionDead('day one');
        Mail::assertSent(YelpSessionExpired::class, 1);

        // The keep-alive runs of the same day stay quiet...
        foreach ([4, 6, 6] as $hours) {
            $this->travel($hours)->hours();
            $service->markSessionDead('same day');
        }
        Mail::assertSent(YelpSessionExpired::class, 1);

        // ...and the same check a day later speaks again, and the day after.
        $this->travel(8)->hours();
        $service->markSessionDead('day two');
        Mail::assertSent(YelpSessionExpired::class, 2);

        $this->travel(24)->hours();
        $service->markSessionDead('day three');
        Mail::assertSent(YelpSessionExpired::class, 3);
    }

    public function test_an_alert_that_could_not_be_sent_does_not_buy_a_day_of_silence(): void
    {
        Config::set('services.yelp.business.proxy', null);
        $service = app(YelpBusinessService::class);

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));
        $service->markSessionDead('mail is down');

        // Put the fake back in place of the throwing mailer.
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstance('mail.manager');
        Mail::fake();
        $service->markSessionDead('mail is back');

        Mail::assertSent(YelpSessionExpired::class, 1);
    }

    public function test_the_alert_goes_to_the_configured_owner_address(): void
    {
        Config::set('services.yelp.business.proxy', null);
        Config::set('mail.review_alert_to', 'patryk@example.com');

        app(YelpBusinessService::class)->markSessionDead('dead');

        Mail::assertSent(YelpSessionExpired::class, fn (YelpSessionExpired $mail) => $mail->hasTo('patryk@example.com'));
    }

    public function test_the_alert_address_is_never_read_from_env_at_run_time(): void
    {
        // The deploy caches config, and env() answers null from then on. An
        // address that exists only in the environment must therefore make no
        // difference here — the config file is what carries it.
        Config::set('services.yelp.business.proxy', null);
        Config::set('mail.review_alert_to', null);
        $this->withEnv('REVIEW_ALERT_TO', 'env-only@example.com', function (): void {
            app(YelpBusinessService::class)->markSessionDead('dead');

            $this->assertSame('env-only@example.com', (require config_path('mail.php'))['review_alert_to']);
        });

        Mail::assertSent(YelpSessionExpired::class, fn (YelpSessionExpired $mail) => $mail->hasTo('noreply@example.com'));
    }

    public function test_the_alert_is_on_in_production_when_the_switch_is_left_unset(): void
    {
        // config/services.php always defines the key (null when
        // YELP_ALERT_EMAILS is unset), so config($key, $default) returned
        // null, never the "production" default: the alert was off in
        // production too.
        Config::set('services.yelp.business.proxy', null);
        Config::set('services.yelp.business.alert_emails', null);
        $this->app->detectEnvironment(fn (): string => 'production');

        app(YelpBusinessService::class)->markSessionDead('dead');

        Mail::assertSent(YelpSessionExpired::class, 1);
    }

    public function test_the_alert_stays_off_outside_production_when_the_switch_is_left_unset(): void
    {
        Config::set('services.yelp.business.proxy', null);
        Config::set('services.yelp.business.alert_emails', null);

        app(YelpBusinessService::class)->markSessionDead('dead');

        Mail::assertNothingSent();
    }

    /**
     * Run $callback with an environment variable set the way a .env line
     * sets it, and put the environment back afterwards.
     */
    private function withEnv(string $key, string $value, callable $callback): void
    {
        $hadEnv = array_key_exists($key, $_ENV);
        $hadServer = array_key_exists($key, $_SERVER);
        $before = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");

        try {
            $callback();
        } finally {
            if ($hadEnv) {
                $_ENV[$key] = $before[0];
            } else {
                unset($_ENV[$key]);
            }
            if ($hadServer) {
                $_SERVER[$key] = $before[1];
            } else {
                unset($_SERVER[$key]);
            }
            putenv($before[2] === false ? $key : "{$key}={$before[2]}");
        }
    }
}
