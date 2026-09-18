<?php

namespace Tests\Unit\Services\Social;

use App\Services\Social\AutomationPlanner;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AutomationPlanner computes a site+platform's weekly posting plan
 * deterministically from cadence settings — same technique as the
 * hard-coded schedule it replaced (routes/console.php, pre-2026-09-18):
 * seed a Mt19937 stream from crc32(site slug + platform + ISO week), so a
 * re-run or a missed tick recomputes the IDENTICAL plan instead of ever
 * posting twice or never.
 */
class AutomationPlannerTest extends TestCase
{
    protected function planner(): AutomationPlanner
    {
        return new AutomationPlanner('America/Chicago');
    }

    protected function randomDaysCadence(int $perWeek = 2): array
    {
        return ['per_week' => $perWeek, 'days' => null, 'window' => ['start' => '09:00', 'end' => '19:00']];
    }

    public function test_the_same_week_always_produces_the_same_plan(): void
    {
        $planner = $this->planner();
        $now = Carbon::parse('2026-09-16 12:00:00', 'America/Chicago'); // Wednesday

        $planA = $planner->plan('gsc', 'instagram', $this->randomDaysCadence(), $now);
        $planB = $planner->plan('gsc', 'instagram', $this->randomDaysCadence(), $now->copy()->addHours(3));

        $this->assertSame($planA['week'], $planB['week']);
        $this->assertSame($planA['slots'], $planB['slots']);
    }

    public function test_next_week_draws_a_different_plan(): void
    {
        $planner = $this->planner();
        $thisWeek = Carbon::parse('2026-09-16 12:00:00', 'America/Chicago');
        $nextWeek = $thisWeek->copy()->addWeek();

        $cadence = $this->randomDaysCadence(1); // one slot/week: a collision would need day AND minute to match
        $planA = $planner->plan('gsc', 'instagram', $cadence, $thisWeek);
        $planB = $planner->plan('gsc', 'instagram', $cadence, $nextWeek);

        $this->assertNotSame($planA['week'], $planB['week']);
        $this->assertNotEquals(
            array_map(fn ($s) => [$s['day'], $s['at']], $planA['slots']),
            array_map(fn ($s) => [$s['day'], $s['at']], $planB['slots']),
        );
    }

    public function test_configured_days_are_honoured_every_week(): void
    {
        $planner = $this->planner();
        $cadence = ['per_week' => 2, 'days' => [2, 4], 'window' => ['start' => '09:00', 'end' => '19:00']];

        foreach (['2026-09-16', '2026-09-23', '2026-10-07'] as $date) {
            $plan = $planner->plan('gsc', 'facebook', $cadence, Carbon::parse($date, 'America/Chicago'));
            $this->assertSame([2, 4], array_column($plan['slots'], 'day'));
        }
    }

    public function test_days_are_capped_to_per_week_in_ascending_order(): void
    {
        $planner = $this->planner();
        $cadence = ['per_week' => 1, 'days' => [5, 1, 3], 'window' => ['start' => '09:00', 'end' => '19:00']];

        $plan = $planner->plan('gsc', 'facebook', $cadence, Carbon::parse('2026-09-16', 'America/Chicago'));

        $this->assertSame([1], array_column($plan['slots'], 'day'));
    }

    public function test_slot_times_always_fall_inside_the_configured_window(): void
    {
        $planner = $this->planner();
        $cadence = ['per_week' => 7, 'days' => null, 'window' => ['start' => '10:15', 'end' => '11:45']];

        $plan = $planner->plan('gsc', 'google_business', $cadence, Carbon::parse('2026-09-16', 'America/Chicago'));

        $this->assertCount(7, $plan['slots']);
        foreach ($plan['slots'] as $slot) {
            [$h, $m] = array_map('intval', explode(':', $slot['at']));
            $minutes = $h * 60 + $m;
            $this->assertGreaterThanOrEqual(10 * 60 + 15, $minutes);
            $this->assertLessThanOrEqual(11 * 60 + 45, $minutes);
        }
    }

    public function test_different_sites_and_platforms_draw_independent_plans(): void
    {
        $planner = $this->planner();
        $now = Carbon::parse('2026-09-16', 'America/Chicago');
        $cadence = $this->randomDaysCadence(1);

        $gsc = $planner->plan('gsc', 'instagram', $cadence, $now);
        $otherSite = $planner->plan('ss', 'instagram', $cadence, $now);
        $otherPlatform = $planner->plan('gsc', 'facebook', $cadence, $now);

        $this->assertNotEquals($gsc['slots'], $otherSite['slots']);
        $this->assertNotEquals($gsc['slots'], $otherPlatform['slots']);
    }

    public function test_due_fires_only_inside_the_five_minute_window_after_the_slot(): void
    {
        $planner = $this->planner();
        // start == end forces a single deterministic minute, independent of
        // the platform's random draw, so the exact slot time is known.
        $cadence = ['per_week' => 1, 'days' => [3], 'window' => ['start' => '13:20', 'end' => '13:20']];

        $slotAt = Carbon::parse('2026-09-16 13:20:00', 'America/Chicago'); // Wednesday = ISO day 3

        $this->assertNull($planner->due('gsc', 'instagram', $cadence, $slotAt->copy()->subMinutes(5)));
        $this->assertSame('2026-09-16 13:20', $planner->due('gsc', 'instagram', $cadence, $slotAt->copy()));
        $this->assertSame('2026-09-16 13:20', $planner->due('gsc', 'instagram', $cadence, $slotAt->copy()->addMinutes(4)));
        $this->assertNull($planner->due('gsc', 'instagram', $cadence, $slotAt->copy()->addMinutes(5)->addSecond()));
    }

    /**
     * The removed routes/console.php schedule drew Instagram's and
     * Facebook's days from ONE shared shuffle specifically so they could
     * never post on the same day. Regression coverage for that guarantee:
     * for the seeded-defaults cadence (per_week=2 each, gs.construction's
     * cadence since the migration that introduced this settings table),
     * the two platforms' day sets must be disjoint every single ISO week —
     * not just usually.
     */
    public function test_instagram_and_facebook_never_share_a_day_across_a_year_of_weeks(): void
    {
        $planner = $this->planner();
        $instagramCadence = $this->randomDaysCadence(2);
        $facebookCadence = $this->randomDaysCadence(2);

        $monday = Carbon::parse('2026-01-05', 'America/Chicago'); // first Monday of ISO week 2026-W02

        for ($week = 0; $week < 52; $week++) {
            $reference = $monday->copy()->addWeeks($week);

            $instagramPlan = $planner->plan('gsc', 'instagram', $instagramCadence, $reference, $facebookCadence);
            $facebookPlan = $planner->plan('gsc', 'facebook', $facebookCadence, $reference, $instagramCadence);

            $instagramDays = array_column($instagramPlan['slots'], 'day');
            $facebookDays = array_column($facebookPlan['slots'], 'day');

            $this->assertSame(
                [],
                array_intersect($instagramDays, $facebookDays),
                "Instagram and Facebook share a day in week {$instagramPlan['week']}: instagram=".implode(',', $instagramDays).' facebook='.implode(',', $facebookDays),
            );
        }
    }

    public function test_next_at_finds_the_upcoming_slot_across_a_week_boundary(): void
    {
        $planner = $this->planner();
        $cadence = ['per_week' => 1, 'days' => [3], 'window' => ['start' => '13:20', 'end' => '13:20']];

        // Just after this week's only slot has passed: next_at must be next
        // week's slot (same weekday, one week later), not null.
        $after = Carbon::parse('2026-09-16 13:21:00', 'America/Chicago');
        $nextAt = $planner->nextAt('gsc', 'instagram', $cadence, $after);

        $this->assertNotNull($nextAt);
        $this->assertSame('2026-09-23 13:20', $nextAt->format('Y-m-d H:i'));
    }
}
