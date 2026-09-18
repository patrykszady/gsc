<?php

namespace App\Services\Social;

use Illuminate\Support\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Computes a site+platform's current-ISO-week posting plan deterministically
 * from its cadence settings (App\Models\SocialAutomationSetting).
 *
 * Same technique the old hard-coded routes/console.php schedule used: seed
 * a Mt19937 stream from crc32(site slug + platform + ISO week), so the same
 * week always draws the same days and times — a re-run, a missed tick, or
 * two ticks landing seconds apart all recompute the IDENTICAL plan instead
 * of drifting or double-posting. Each site+platform now draws its own
 * independent stream (rather than one shared draw split between Instagram
 * and Facebook), because cadence is now configured per platform per site.
 */
class AutomationPlanner
{
    public function __construct(protected ?string $timezone = null)
    {
        $this->timezone ??= (string) config('social-automation.timezone', 'America/Chicago');
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * This week's plan for the API: week label, every slot (with whether it
     * has already passed), and the next upcoming slot (which may fall in
     * next week once this week's are all done).
     *
     * @param  array{per_week:int, days:?array<int,int>, window:array{start:string,end:string}}  $cadence
     */
    public function plan(string $siteSlug, string $platform, array $cadence, ?Carbon $now = null): array
    {
        $now = $this->normalize($now);
        $slots = $this->weekSlots($siteSlug, $platform, $cadence, $now);

        $decorated = array_map(function (array $slot) use ($now) {
            $slot['done'] = $this->slotAt($slot)->lessThanOrEqualTo($now);

            return $slot;
        }, $slots);

        $nextAt = $this->nextAt($siteSlug, $platform, $cadence, $now);

        return [
            'week' => $this->weekLabel($now),
            'slots' => $decorated,
            'next_at' => $nextAt?->toIso8601String(),
        ];
    }

    /**
     * The slot id ("Y-m-d H:i") due within the last 5 minutes, or null.
     *
     * Half-open (now-5min, now] window: with ticks running every 5 minutes
     * on the cron grid, every slot minute falls inside exactly one tick's
     * window, so a slot fires exactly once even across two ticks straddling
     * its minute.
     */
    public function due(string $siteSlug, string $platform, array $cadence, ?Carbon $now = null): ?string
    {
        $now = $this->normalize($now);
        $windowStart = $now->copy()->subMinutes(5);

        foreach ($this->weekSlots($siteSlug, $platform, $cadence, $now) as $slot) {
            $slotAt = $this->slotAt($slot);
            if ($slotAt->greaterThan($windowStart) && $slotAt->lessThanOrEqualTo($now)) {
                return $slot['date'].' '.$slot['at'];
            }
        }

        return null;
    }

    /** The next upcoming slot, checking this week then next week. */
    public function nextAt(string $siteSlug, string $platform, array $cadence, ?Carbon $now = null): ?Carbon
    {
        $now = $this->normalize($now);

        for ($weekOffset = 0; $weekOffset <= 1; $weekOffset++) {
            $reference = $now->copy()->addWeeks($weekOffset);

            foreach ($this->weekSlots($siteSlug, $platform, $cadence, $reference) as $slot) {
                $slotAt = $this->slotAt($slot);
                if ($slotAt->greaterThan($now)) {
                    return $slotAt;
                }
            }
        }

        return null;
    }

    /**
     * The deterministic set of {day, date, at} slots for the ISO week
     * containing $reference — no "done" flag (that depends on the caller's
     * $now, which may differ from $reference when peeking at next week).
     *
     * @return list<array{day:int, date:string, at:string}>
     */
    protected function weekSlots(string $siteSlug, string $platform, array $cadence, Carbon $reference): array
    {
        $weekLabel = $this->weekLabel($reference);
        $seed = crc32(sprintf('%s|%s|%s', $siteSlug, $platform, $weekLabel));
        $randomizer = new Randomizer(new Mt19937($seed));

        $perWeek = max(1, min(7, (int) ($cadence['per_week'] ?? 1)));
        $configuredDays = $cadence['days'] ?? null;

        if (is_array($configuredDays) && $configuredDays !== []) {
            // Explicit days: deterministic (no draw needed), sorted, capped
            // to per_week rather than randomly chosen among them.
            $days = collect($configuredDays)
                ->map(fn ($d) => (int) $d)
                ->unique()
                ->sort()
                ->values()
                ->take($perWeek)
                ->all();
        } else {
            $days = array_slice($randomizer->shuffleArray(range(1, 7)), 0, $perWeek);
            sort($days);
        }

        [$startMinutes, $endMinutes] = $this->windowMinutes($cadence['window'] ?? []);
        $weekStart = $reference->copy()->startOfWeek(Carbon::MONDAY);

        $slots = [];
        foreach ($days as $day) {
            $minute = $endMinutes > $startMinutes
                ? $randomizer->getInt($startMinutes, $endMinutes)
                : $startMinutes;

            $slots[] = [
                'day' => $day,
                'date' => $weekStart->copy()->addDays($day - 1)->format('Y-m-d'),
                'at' => sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60),
            ];
        }

        usort($slots, fn ($a, $b) => $a['date'] <=> $b['date']);

        return $slots;
    }

    /** @param array{start?:string,end?:string} $window */
    protected function windowMinutes(array $window): array
    {
        [$startH, $startM] = array_pad(explode(':', $window['start'] ?? '09:00'), 2, '0');
        [$endH, $endM] = array_pad(explode(':', $window['end'] ?? '17:00'), 2, '0');

        return [
            ((int) $startH) * 60 + (int) $startM,
            ((int) $endH) * 60 + (int) $endM,
        ];
    }

    /** @param array{date:string, at:string} $slot */
    protected function slotAt(array $slot): Carbon
    {
        return Carbon::parse($slot['date'].' '.$slot['at'], $this->timezone);
    }

    protected function weekLabel(Carbon $reference): string
    {
        return sprintf('%04d-W%02d', (int) $reference->format('o'), (int) $reference->format('W'));
    }

    protected function normalize(?Carbon $now): Carbon
    {
        return ($now ?? Carbon::now($this->timezone))->copy()->setTimezone($this->timezone);
    }
}
