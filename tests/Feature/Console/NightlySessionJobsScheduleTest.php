<?php

namespace Tests\Feature\Console;

use App\Services\YelpBusinessService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The overnight Yelp / Instagram / Business Profile lines in
 * routes/console.php: when they run, when they stand down, and what they
 * leave in the log when they fail.
 *
 * 2026-11-12: all three had been failing nightly without anyone being told.
 * The Yelp sign-in now really opens a browser (it used to be aborted before
 * it could), so the lines around it are spaced for that and it is kept from
 * spending a second paid attempt minutes after the first.
 */
class NightlySessionJobsScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('services.yelp.business.email', 'owner@example.com');
        Config::set('services.yelp.business.password', 'secret');
        Config::set('services.yelp.business.proxy', 'http://user:pass@proxy.test:8000');
        Config::set('services.twocaptcha.api_key', 'captcha-key');
    }

    /**
     * @return list<Event>
     */
    private function scheduledEvents(): array
    {
        Facade::clearResolvedInstances();
        $this->app->forgetInstance(Schedule::class);
        require base_path('routes/console.php');

        return app(Schedule::class)->events();
    }

    private function event(string $command): Event
    {
        $event = collect($this->scheduledEvents())
            ->first(fn (Event $e) => str_ends_with((string) $e->command, "artisan' {$command}") || str_ends_with((string) $e->command, "artisan {$command}"));

        $this->assertNotNull($event, "{$command} is not scheduled");

        return $event;
    }

    public function test_no_two_browser_jobs_share_the_window_between_two_and_three(): void
    {
        // Instagram first and clear of everything; the Yelp check, whose
        // queued sign-in can hold a headed Chromium until about 02:24; then
        // the sign-in backstop, which can hold one until about 02:39.
        $this->assertSame('5 2 * * *', $this->event('instagram:check-session')->expression);
        $this->assertSame('15 2 * * *', $this->event('yelp:check-session')->expression);
        $this->assertSame('30 2 * * *', $this->event('yelp:login')->expression);

        // The six-hourly keep-alive never lands in the 02:00 hour.
        $this->assertSame('20 */6 * * *', $this->event('yelp:keep-session')->expression);
    }

    public function test_a_killed_run_cannot_switch_the_next_nights_run_off(): void
    {
        // withoutOverlapping() with no argument holds its lock for 24 hours
        // when a run dies without finishing.
        foreach ([
            'instagram:check-session' => 10,
            'yelp:check-session' => 10,
            'yelp:keep-session' => 10,
            'yelp:login' => 20,
        ] as $command => $minutes) {
            $event = $this->event($command);

            $this->assertTrue($event->withoutOverlapping, "{$command} may overlap itself");
            $this->assertSame($minutes, $event->expiresAt, "{$command} holds its overlap lock too long");
        }
    }

    public function test_the_sign_in_backstop_runs_when_the_session_is_dead_and_nothing_has_tried(): void
    {
        Cache::put('yelp.session_dead', ['at' => now()->toIso8601String(), 'note' => 'x'], now()->addHours(12));

        $event = $this->event('yelp:login');

        $this->assertTrue($event->filtersPass($this->app));
        // Up to ~9 minutes of browser: must not hold the rest of 02:30's
        // events behind it in schedule:run.
        $this->assertTrue($event->runInBackground);
    }

    /**
     * @return array<string, array{callable(): void}>
     */
    public static function reasonsToStandDown(): array
    {
        return [
            'the session is not dead' => [fn () => Cache::forget('yelp.session_dead')],
            'the 02:15 check already queued an attempt' => [fn () => Cache::put('yelp.auto_login_attempted', true, now()->addMinutes(30))],
            'no proxy, so the captcha solver cannot work' => [fn () => Config::set('services.yelp.business.proxy', null)],
            'Yelp wants a code only a person can enter' => [fn () => app(YelpBusinessService::class)->recordAutoLoginResult([
                'ok' => false, 'code' => 'needs_verification', 'hint' => 'Yelp wants a code.',
            ])],
            'the attempts for the day are used' => [function (): void {
                foreach (range(1, YelpBusinessService::AUTO_LOGIN_DAILY_LIMIT) as $ignored) {
                    app(YelpBusinessService::class)->recordAutoLoginResult(['ok' => false, 'code' => 'datadome_blocked', 'hint' => 'blocked']);
                }
            }],
        ];
    }

    #[DataProvider('reasonsToStandDown')]
    public function test_the_sign_in_backstop_stands_down(callable $arrange): void
    {
        Config::set('services.yelp.business.alert_emails', false);
        Cache::put('yelp.session_dead', ['at' => now()->toIso8601String(), 'note' => 'x'], now()->addHours(12));
        $arrange();

        $this->assertFalse($this->event('yelp:login')->filtersPass($this->app));
    }

    public function test_the_photo_queue_line_does_not_run_where_the_central_admin_uploads_the_photos(): void
    {
        // Production (GBP_PHOTOS_OWNED_BY=ss-systems): the command had
        // nothing to do and refused with exit 1 — "Scheduled GBP sync
        // failed", every night.
        Config::set('services.google.business_profile.photos_owned_by', 'ss-systems');
        $this->assertFalse($this->event('google-business-profile:sync --upload --queue')->filtersPass($this->app));

        Config::set('services.google.business_profile.photos_owned_by', 'site');
        $this->assertTrue($this->event('google-business-profile:sync --upload --queue')->filtersPass($this->app));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function linesWithAFailureHook(): array
    {
        return [
            'yelp:check-session' => ['yelp:check-session'],
            'yelp:login' => ['yelp:login'],
            'instagram:check-session' => ['instagram:check-session'],
            'google-business-profile:sync' => ['google-business-profile:sync --upload --queue'],
        ];
    }

    #[DataProvider('linesWithAFailureHook')]
    public function test_a_failed_run_logs_which_command_and_where_its_reason_is(string $command): void
    {
        $event = $this->event($command);
        Log::spy();

        $event->finish($this->app, 1);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => $message === "Scheduled {$command} failed — the reason is at the end of storage/logs/schedule.log")
            ->once();
    }

    public function test_a_run_that_worked_logs_nothing(): void
    {
        $event = $this->event('yelp:check-session');
        Log::spy();

        $event->finish($this->app, 0);

        Log::shouldNotHaveReceived('error');
    }
}
