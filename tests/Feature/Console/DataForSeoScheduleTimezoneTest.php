<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every DataForSEO-spending schedule must be pinned to America/Chicago so
 * its wall-clock trigger doesn't shift an hour across a DST boundary — the
 * week-over-week chevrons on the SEO page compare same-length windows, and a
 * server-UTC schedule drifts against them twice a year. Before this, only
 * yelp:sync-leads and seo:track-rankings carried the pin; every other
 * DataForSEO command ran on raw server UTC.
 */
class DataForSeoScheduleTimezoneTest extends TestCase
{
    /**
     * routes/console.php is only required once per Kernel (commandsLoaded
     * guard), and by the time a test reaches here something else may
     * already have triggered that — so force a clean re-registration onto a
     * fresh Schedule instance rather than relying on Kernel::bootstrap().
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

    /** @return array<string, array{0: string}> */
    public static function dataForSeoCommands(): array
    {
        return collect([
            'seo:map-pack-grid',
            'seo:domain-overview',
            'seo:backlink-gap',
            'seo:ai-mentions',
            'seo:keyword-research',
            'seo:intel onpage',
            'seo:intel labs',
            'seo:intel backlinks',
            'seo:intel serp',
            'seo:intel content_analysis',
            'seo:intel ai_optimization',
            'seo:intel trends',
            'seo:intel domain_analytics',
            'seo:intel business_data',
            'seo:map-pack-competitors',
            'seo:dataforseo-balance-check',
        ])->mapWithKeys(fn ($c) => [$c => [$c]])->all();
    }

    #[DataProvider('dataForSeoCommands')]
    public function test_dataforseo_command_is_pinned_to_chicago(string $command): void
    {
        $events = $this->scheduledEvents();
        $match = collect($events)->first(fn ($e) => str_contains($e->command ?? '', $command));

        $this->assertNotNull($match, "no scheduled event found for [{$command}]");
        $this->assertSame('America/Chicago', $match->timezone, "[{$command}] is not pinned to America/Chicago");
    }

    public function test_a_command_that_never_spends_dataforseo_money_is_left_alone(): void
    {
        // Negative control: hive:sync spends nothing with DataForSEO and was
        // never asked to be pinned. Without this, a test that only checks
        // "is Chicago" for our list would not catch an over-broad fix (e.g.
        // a global Schedule::timezone() default applied to every event).
        $events = $this->scheduledEvents();
        $match = collect($events)->first(fn ($e) => str_contains($e->command ?? '', 'hive:sync'));

        $this->assertNotNull($match);
        $this->assertNotSame('America/Chicago', $match->timezone);
    }
}
