<?php

namespace App\Support\Seo;

use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The URL Inspection API's daily allowance, shared by everything that calls it.
 *
 * Google allows 2,000 inspections a day and 600 a minute per property. Three
 * callers draw on that one allowance — the nightly sweep, a Console CSV
 * import, and the admin's inspect-this-URL button — and none of them could
 * see the others, so an import on a sweep day used to run the property into
 * HTTP 429 and lose the rest of the run. This counts the calls so each
 * caller can ask what is left and stop while it still has an answer.
 *
 * The counter is per site, because the allowance is per property and each
 * site has its own. It resets at midnight Pacific, which is where Google's
 * daily quotas turn over, not local midnight.
 */
final class UrlInspectionQuota
{
    /** Where Google's daily quotas roll over. */
    public const RESET_TIMEZONE = 'America/Los_Angeles';

    public static function dailyLimit(): int
    {
        return max(1, (int) config('services.google.search_console.inspection_daily_quota', 2000));
    }

    /** Calls a minute, the other published ceiling — callers pace themselves with this. */
    public static function perMinuteLimit(): int
    {
        return max(1, (int) config('services.google.search_console.inspection_per_minute_quota', 600));
    }

    public static function used(): int
    {
        return (int) Cache::get(self::key(), 0);
    }

    public static function remaining(): int
    {
        return max(0, self::dailyLimit() - self::used());
    }

    /**
     * How many of $wanted calls may actually be made now.
     *
     * Reserving does not consume: a caller that stops early should not burn
     * the allowance it never used, so consume() is called per real request.
     */
    public static function reserve(int $wanted): int
    {
        return max(0, min($wanted, self::remaining()));
    }

    public static function consume(int $calls = 1): void
    {
        $key = self::key();
        // add() then increment(): increment on a missing key is a no-op on
        // some stores, which would leave the day's count stuck at zero.
        Cache::add($key, 0, self::resetsAt());
        Cache::increment($key, $calls);
    }

    /**
     * Google refused on quota — treat the day as spent rather than keep
     * asking, whatever our own count says (another client on the same
     * property spends from the same allowance).
     */
    public static function markExhausted(): void
    {
        Cache::put(self::key(), self::dailyLimit(), self::resetsAt());
    }

    public static function resetsAt(): Carbon
    {
        return Carbon::now(self::RESET_TIMEZONE)->addDay()->startOfDay();
    }

    /** @return array<string, mixed> */
    public static function status(): array
    {
        return [
            'used' => self::used(),
            'remaining' => self::remaining(),
            'daily_limit' => self::dailyLimit(),
            'per_minute_limit' => self::perMinuteLimit(),
            'resets_at' => self::resetsAt()->toIso8601String(),
        ];
    }

    public static function key(): string
    {
        return Tenancy::cacheKey('gsc.url-inspection.'.Carbon::now(self::RESET_TIMEZONE)->toDateString());
    }
}
