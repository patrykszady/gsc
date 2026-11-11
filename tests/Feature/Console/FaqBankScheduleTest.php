<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * seo:faq-bank's schedule entry (kit 0.24.0): once per active tenant
 * (tenants:run, like the other per-site seo:* commands), 04:20
 * America/Chicago, 150 pages a night, its own log — see
 * BingCoverageScheduleTest for the scheduledEvents() re-registration
 * pattern this follows.
 */
class FaqBankScheduleTest extends TestCase
{
    /** @return list<Event> */
    private function scheduledEvents(): array
    {
        Facade::clearResolvedInstances();
        $this->app->forgetInstance(Schedule::class);
        require base_path('routes/console.php');

        return app(Schedule::class)->events();
    }

    public function test_seo_faq_bank_runs_per_tenant_at_04_20_chicago_with_its_own_log(): void
    {
        $event = collect($this->scheduledEvents())->first(fn (Event $e) => str_contains($e->command ?? '', 'seo:faq-bank'));

        $this->assertNotNull($event, 'seo:faq-bank is not scheduled');
        $this->assertStringContainsString("tenants:run 'seo:faq-bank --limit=150' --continue-on-error", $event->command, 'per tenant, 150 pages a night');
        $this->assertSame('20 4 * * *', $event->expression);
        $this->assertSame('America/Chicago', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringEndsWith('logs/seo-faq-bank.log', (string) $event->output);
    }
}
