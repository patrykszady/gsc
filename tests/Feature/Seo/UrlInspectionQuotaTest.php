<?php

namespace Tests\Feature\Seo;

use App\Support\Seo\UrlInspectionQuota;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The URL Inspection allowance: 2,000 calls a day per property, shared by
 * the nightly sweep, a Console CSV import and the admin's inspect button.
 */
class UrlInspectionQuotaTest extends TestCase
{
    public function test_it_counts_calls_against_the_daily_limit(): void
    {
        config(['services.google.search_console.inspection_daily_quota' => 2000]);

        $this->assertSame(0, UrlInspectionQuota::used());
        $this->assertSame(2000, UrlInspectionQuota::remaining());

        UrlInspectionQuota::consume();
        UrlInspectionQuota::consume(9);

        $this->assertSame(10, UrlInspectionQuota::used());
        $this->assertSame(1990, UrlInspectionQuota::remaining());
    }

    public function test_reserving_never_promises_more_than_is_left_and_does_not_itself_spend(): void
    {
        config(['services.google.search_console.inspection_daily_quota' => 100]);
        UrlInspectionQuota::consume(90);

        $this->assertSame(10, UrlInspectionQuota::reserve(500), 'clamped to what is left');
        $this->assertSame(5, UrlInspectionQuota::reserve(5), 'a smaller ask is untouched');
        // A caller that stops early must not burn the allowance it never used.
        $this->assertSame(90, UrlInspectionQuota::used());
    }

    public function test_googles_refusal_spends_the_day_whatever_our_own_count_says(): void
    {
        config(['services.google.search_console.inspection_daily_quota' => 2000]);
        UrlInspectionQuota::consume(5);

        // Another client on the same property spends from the same allowance,
        // so a 429 is the authority, not our counter.
        UrlInspectionQuota::markExhausted();

        $this->assertSame(0, UrlInspectionQuota::remaining());
        $this->assertSame(0, UrlInspectionQuota::reserve(1));
    }

    public function test_the_count_is_per_day_and_turns_over_at_midnight_pacific(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 18:00', 'America/Los_Angeles'));
        UrlInspectionQuota::consume(1200);
        $this->assertSame(1200, UrlInspectionQuota::used());

        // Still the same Pacific day, even though Chicago has ticked over.
        Carbon::setTestNow(Carbon::parse('2026-09-14 23:30', 'America/Los_Angeles'));
        $this->assertSame(1200, UrlInspectionQuota::used());

        Carbon::setTestNow(Carbon::parse('2026-09-15 00:30', 'America/Los_Angeles'));
        $this->assertSame(0, UrlInspectionQuota::used(), 'a new Pacific day is a new allowance');

        Carbon::setTestNow();
    }
}
