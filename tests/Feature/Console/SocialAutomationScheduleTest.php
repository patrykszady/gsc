<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * The hard-coded, single-tenant Meta/GBP posting blocks (the $metaPostPlan
 * closure, the three social:post Schedule::command() entries, and the GBP
 * safety-net Schedule::call()) are gone from routes/console.php, replaced
 * by one social:automation-tick entry. Every OTHER schedule entry — most
 * pointedly google-business-profile:sync --upload (line ~170) and
 * socials:check, which sit right next to what was removed — must survive
 * untouched.
 */
class SocialAutomationScheduleTest extends TestCase
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

    public function test_the_hard_coded_meta_and_gbp_blocks_are_gone(): void
    {
        $events = $this->scheduledEvents();

        $survivors = collect($events)->filter(function (Event $e) {
            $command = $e->command ?? '';

            return str_contains($command, 'social:post --platform=instagram')
                || str_contains($command, 'social:post --platform=facebook')
                || str_contains($command, 'social:post --platform=google_business --queue --random-delay');
        });

        $this->assertCount(0, $survivors, 'a hard-coded social:post schedule entry survived the removal');

        $named = collect($events)->first(fn (Event $e) => ($e->description ?? null) === 'gbp-safety-net-catchup-post');
        $this->assertNull($named, 'the GBP safety-net Schedule::call() survived the removal');
    }

    public function test_the_new_tick_is_registered_every_five_minutes_on_one_server(): void
    {
        $events = $this->scheduledEvents();

        $tick = collect($events)->first(fn (Event $e) => str_contains($e->command ?? '', 'social:automation-tick'));

        $this->assertNotNull($tick, 'social:automation-tick is not scheduled');
        $this->assertSame('*/5 * * * *', $tick->expression);
        $this->assertTrue($tick->onOneServer);
        $this->assertSame('America/Chicago', $tick->timezone);
    }

    public function test_gbp_media_sync_and_socials_check_are_untouched(): void
    {
        $events = $this->scheduledEvents();

        $gbpSync = collect($events)->first(fn (Event $e) => str_contains($e->command ?? '', 'google-business-profile:sync --upload --queue'));
        $this->assertNotNull($gbpSync, 'google-business-profile:sync --upload --queue is missing');
        $this->assertSame('30 2 * * *', $gbpSync->expression);

        $socialsCheck = collect($events)->first(fn (Event $e) => str_contains($e->command ?? '', 'socials:check'));
        $this->assertNotNull($socialsCheck, 'socials:check is missing');

        $socialHealth = collect($events)->first(fn (Event $e) => str_contains($e->command ?? '', 'social:health'));
        $this->assertNotNull($socialHealth, 'social:health (weekly health check) is missing');
    }
}
