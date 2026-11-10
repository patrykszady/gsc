<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * seo:bing-coverage's schedule entry (kit 0.22.0): 40 minutes after
 * seo:bing-sync (05:45 America/Chicago), production only — see
 * SocialAutomationScheduleTest/DataForSeoScheduleTimezoneTest for the
 * scheduledEvents() re-registration pattern this follows.
 */
class BingCoverageScheduleTest extends TestCase
{
    /** @return list<Event> */
    private function scheduledEvents(): array
    {
        Facade::clearResolvedInstances();
        $this->app->forgetInstance(Schedule::class);
        require base_path('routes/console.php');

        return app(Schedule::class)->events();
    }

    public function test_seo_bing_coverage_is_scheduled_40_minutes_after_bing_sync_production_only(): void
    {
        $events = $this->scheduledEvents();

        $bingSync = collect($events)->first(fn (Event $e) => str_contains($e->command ?? '', 'seo:bing-sync'));
        $this->assertNotNull($bingSync, 'seo:bing-sync is missing — did a neighbouring entry move?');
        $this->assertSame('45 5 * * *', $bingSync->expression, 'seo:bing-sync is no longer at 05:45 America/Chicago');

        $coverage = collect($events)->first(fn (Event $e) => str_contains($e->command ?? '', 'seo:bing-coverage'));

        $this->assertNotNull($coverage, 'seo:bing-coverage is not scheduled');
        $this->assertStringContainsString('--limit=300', $coverage->command);
        $this->assertSame('25 6 * * *', $coverage->expression, '06:25 is 40 minutes after seo:bing-sync\'s 05:45');
        $this->assertSame('America/Chicago', $coverage->timezone);
        $this->assertTrue($coverage->withoutOverlapping);
        $this->assertTrue($coverage->onOneServer);
        $this->assertSame(['production'], $coverage->environments);
    }
}
