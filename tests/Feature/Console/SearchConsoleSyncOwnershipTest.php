<?php

namespace Tests\Feature\Console;

use App\Support\Seo\SearchConsoleSyncOwnership;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use SsSystems\Platform\Seo\SearchConsoleSyncRule;
use Tests\TestCase;

/**
 * GSC_SYNC_OWNED_BY mirrors GBP_PHOTOS_OWNED_BY's shape (2026-09-22): 'site'
 * (this app's own schedule, the default) or 'ss-systems', for if/when the
 * central admin runs the sync itself instead. Only the SCHEDULE reads it —
 * a manual sync (the command, or the admin's "sync now") must never be
 * silently swallowed just because the schedule has moved elsewhere.
 */
class SearchConsoleSyncOwnershipTest extends TestCase
{
    public function test_the_switch_reads_from_config_and_defaults_to_this_site(): void
    {
        config(['services.google.search_console.sync_owned_by' => 'ss-systems']);
        $this->assertFalse(SearchConsoleSyncOwnership::ownedHere());

        config(['services.google.search_console.sync_owned_by' => 'site']);
        $this->assertTrue(SearchConsoleSyncOwnership::ownedHere());

        config(['services.google.search_console.sync_owned_by' => null]);
        $this->assertTrue(SearchConsoleSyncOwnership::ownedHere());
    }

    /**
     * routes/console.php is only required once per Kernel (commandsLoaded
     * guard) — force a clean re-registration onto a fresh Schedule instance,
     * same technique as DataForSeoScheduleTimezoneTest.
     *
     * @return list<Event>
     */
    private function scheduledEvents(): array
    {
        Facade::clearResolvedInstances();
        $this->app->forgetInstance(Schedule::class);
        require base_path('routes/console.php');

        return app(Schedule::class)->events();
    }

    private function gscSyncEvent(): Event
    {
        $match = collect($this->scheduledEvents())->first(fn ($e) => str_contains($e->command ?? '', 'seo:gsc-sync'));
        $this->assertNotNull($match, 'no scheduled event found for seo:gsc-sync');

        return $match;
    }

    public function test_the_schedule_fires_when_enabled_and_owned_here(): void
    {
        config([
            'services.google.search_console.enabled' => true,
            'services.google.search_console.sync_owned_by' => 'site',
        ]);

        $this->assertTrue($this->gscSyncEvent()->filtersPass($this->app));
    }

    public function test_the_schedule_does_not_fire_once_the_central_admin_owns_it(): void
    {
        config([
            'services.google.search_console.enabled' => true,
            'services.google.search_console.sync_owned_by' => 'ss-systems',
        ]);

        $this->assertFalse($this->gscSyncEvent()->filtersPass($this->app));
    }

    public function test_the_days_option_comes_from_the_kit_constant_not_a_repeated_literal(): void
    {
        config([
            'services.google.search_console.enabled' => true,
            'services.google.search_console.sync_owned_by' => 'site',
        ]);

        $event = $this->gscSyncEvent();

        $this->assertStringContainsString(
            '--days='.SearchConsoleSyncRule::DEFAULT_DAYS,
            $event->command,
        );
    }
}
