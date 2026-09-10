<?php

namespace App\Support\Reviews;

use App\Models\PlatformSetting;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Cache;

/**
 * Per-site Houzz review import, configured on the admin Platforms page.
 *
 * Every value is a site-scoped PlatformSetting row, so each tenant has its
 * own profile URL, on/off switch and last-run record. The profile URL shares
 * its key with the Social Media page's profile links, so the two screens
 * always show the same address.
 */
final class HouzzReviews
{
    public const URL_KEY = 'socials.url.houzz';

    public const ENABLED_KEY = 'houzz.reviews.enabled';

    public const LAST_RUN_KEY = 'houzz.reviews.last_run';

    /** A run that never reports back stops showing as "running" after this long. */
    public const RUNNING_TTL_MINUTES = 20;

    public static function profileUrl(): ?string
    {
        $url = trim((string) PlatformSetting::get(self::URL_KEY, (string) config('socials.houzz.url', '')));

        return $url !== '' ? $url : null;
    }

    /** Off until somebody switches it on for the site — a tenant never inherits another's import. */
    public static function enabled(): bool
    {
        return PlatformSetting::get(self::ENABLED_KEY) === '1';
    }

    public static function save(?string $profileUrl, bool $enabled): void
    {
        PlatformSetting::put(self::URL_KEY, trim((string) $profileUrl) !== '' ? trim((string) $profileUrl) : null);
        PlatformSetting::put(self::ENABLED_KEY, $enabled ? '1' : '0');
    }

    /** @param  array<string, int|string|null>  $summary */
    public static function recordRun(array $summary, ?string $error = null): void
    {
        PlatformSetting::put(self::LAST_RUN_KEY, (string) json_encode([
            'at' => now()->toIso8601String(),
            'error' => $error,
            ...$summary,
        ]));
        self::markRunning(false);
    }

    /** @return array<string, mixed>|null */
    public static function lastRun(): ?array
    {
        $raw = PlatformSetting::get(self::LAST_RUN_KEY);
        $data = $raw ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    public static function markRunning(bool $running): void
    {
        $key = Tenancy::cacheKey('houzz.reviews.running');
        $running ? Cache::put($key, true, now()->addMinutes(self::RUNNING_TTL_MINUTES)) : Cache::forget($key);
    }

    public static function isRunning(): bool
    {
        return (bool) Cache::get(Tenancy::cacheKey('houzz.reviews.running'), false);
    }

    public static function brandName(): string
    {
        return (string) config('brand.name', config('app.name'));
    }

    /**
     * Houzz writes the reviewed business into every review URL
     * ("/viewReview/1453810/GS-Construction-review"), so a reviewer's
     * activity page can list reviews of other pros too. Compare with
     * punctuation and spacing stripped: "J. Peterson Design" must match
     * "J-Peterson-Design-review".
     */
    public static function reviewUrlBelongsTo(string $url, string $brand): bool
    {
        $norm = fn (string $s) => (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s));
        $needle = $norm($brand);

        return $needle !== '' && str_contains($norm($url), $needle.'review');
    }
}
