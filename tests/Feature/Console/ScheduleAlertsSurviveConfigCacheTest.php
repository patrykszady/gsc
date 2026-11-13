<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The deploy runs `php artisan config:cache`, after which env() answers
 * only its default: nothing outside config/ may read an alert address (or
 * anything else that decides whether a schedule line alerts) from env().
 *
 * routes/console.php read env('SEO_ALERT_EMAIL') at schedule-definition
 * time, so the two Search Console alerts were never attached in production.
 */
class ScheduleAlertsSurviveConfigCacheTest extends TestCase
{
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

    private function event(string $needle): Event
    {
        $event = collect($this->scheduledEvents())->first(fn (Event $e) => str_contains((string) $e->command, $needle));

        $this->assertNotNull($event, "{$needle} is not scheduled");

        return $event;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function searchConsoleAlerts(): array
    {
        return [
            'sitemap status' => ['seo:gsc-sitemap-status'],
            'critical page health' => ['seo:gsc-critical-health'],
        ];
    }

    #[DataProvider('searchConsoleAlerts')]
    public function test_a_failed_search_console_check_is_mailed_to_the_configured_address(string $command): void
    {
        Config::set('seo.alert_email', 'owner@example.com');
        $event = $this->event($command);

        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('raw')->once()->withArgs(function (string $text, \Closure $build): bool {
            $message = Mockery::mock();
            $message->shouldReceive('to')->once()->with(['owner@example.com'])->andReturnSelf();
            $message->shouldReceive('subject')->once()->andReturnSelf();
            $build($message);

            return true;
        });
        $this->app->instance(Mailer::class, $mailer);
        $this->app->instance('mailer', $mailer);

        $event->finish($this->app, 1);
    }

    #[DataProvider('searchConsoleAlerts')]
    public function test_a_check_that_passed_mails_nothing(string $command): void
    {
        Config::set('seo.alert_email', 'owner@example.com');
        $event = $this->event($command);

        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldNotReceive('raw');
        $this->app->instance(Mailer::class, $mailer);
        $this->app->instance('mailer', $mailer);

        $event->finish($this->app, 0);
    }

    #[DataProvider('searchConsoleAlerts')]
    public function test_no_address_means_no_mail(string $command): void
    {
        Config::set('seo.alert_email', null);
        $event = $this->event($command);

        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldNotReceive('raw');
        $this->app->instance(Mailer::class, $mailer);
        $this->app->instance('mailer', $mailer);

        $event->finish($this->app, 1);
    }

    public function test_the_address_comes_from_the_config_file(): void
    {
        $_ENV['SEO_ALERT_EMAIL'] = $_SERVER['SEO_ALERT_EMAIL'] = 'seo-alerts@example.com';
        putenv('SEO_ALERT_EMAIL=seo-alerts@example.com');

        try {
            $this->assertSame('seo-alerts@example.com', (require config_path('seo.php'))['alert_email']);
        } finally {
            unset($_ENV['SEO_ALERT_EMAIL'], $_SERVER['SEO_ALERT_EMAIL']);
            putenv('SEO_ALERT_EMAIL');
        }
    }

    public function test_the_schedule_file_never_reads_env(): void
    {
        $code = collect(token_get_all((string) file_get_contents(base_path('routes/console.php'))))
            ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($token) => is_array($token) ? $token[1] : $token)
            ->implode('');

        $this->assertDoesNotMatchRegularExpression(
            '/\benv\s*\(/',
            $code,
            'routes/console.php calls env(): under config:cache it answers the default, never the .env value',
        );
    }

    public function test_no_failure_hook_guesses_at_the_cause(): void
    {
        $this->assertStringNotContainsString(
            'failed (balance?)',
            (string) file_get_contents(base_path('routes/console.php')),
            'a hook that guesses "balance?" sends the reader after the wrong cause',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paidCommandsWithAFailureHook(): array
    {
        return [
            'keyword research' => ['seo:keyword-research'],
            'intel: on-page crawl' => ['seo:intel onpage --budget=1'],
            'intel: backlinks' => ['seo:intel backlinks'],
            'intel: AI optimisation' => ['seo:intel ai_optimization'],
        ];
    }

    #[DataProvider('paidCommandsWithAFailureHook')]
    public function test_a_failed_paid_command_logs_its_name_and_its_output_file(string $needle): void
    {
        $event = $this->event($needle);
        Log::spy();

        $event->finish($this->app, 1);

        $name = implode(' ', array_slice(explode(' ', $needle), 0, str_starts_with($needle, 'seo:intel') ? 2 : 1));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => $message === "Scheduled {$name} failed — the reason is at the end of storage/logs/schedule.log")
            ->once();
    }
}
