<?php

namespace App\Support\Reviews;

use App\Jobs\RunSeoChannelSyncJob;
use App\Models\PlatformSetting;
use App\Models\Site;
use App\Support\SiteConfig;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Cache;

/**
 * Per-site Houzz review import, shown on the admin Platforms page.
 *
 * There is nothing to switch on: a site with a Houzz profile URL gets its
 * new reviews imported every week, like Yelp. The URL is the Houzz link
 * edited under Social Media → profile links (one site-scoped
 * PlatformSetting); Platforms shows it read-only next to the import status
 * and last-run record.
 */
final class HouzzReviews
{
    public const URL_KEY = 'socials.url.houzz';

    public const LAST_RUN_KEY = 'houzz.reviews.last_run';

    /** A run that never reports back stops showing as "running" after this long. */
    public const RUNNING_TTL_MINUTES = 20;

    public static function profileUrl(): ?string
    {
        $stored = trim((string) PlatformSetting::get(self::URL_KEY));
        if ($stored !== '') {
            return $stored;
        }

        // config/socials.php is gs.construction's. A site that has not set
        // its own Houzz page would otherwise inherit GS's profile and import
        // GS's reviews as its own.
        if (! SiteConfig::owns('socials.houzz.url')) {
            return null;
        }

        $url = trim((string) config('socials.houzz.url', ''));

        return $url !== '' ? $url : null;
    }

    /** Imports run automatically for any site with a profile URL. */
    public static function automated(): bool
    {
        return self::profileUrl() !== null;
    }

    public static function save(?string $profileUrl): void
    {
        $url = trim((string) $profileUrl);
        PlatformSetting::put(self::URL_KEY, $url !== '' ? $url : null);
    }

    /**
     * Monday's schedule: one queued import per active site with a profile
     * URL, run as that site. Returns the slugs dispatched.
     *
     * @return list<string>
     */
    public static function dispatchScheduledImports(): array
    {
        $dispatched = [];

        Tenancy::each(function (Site $site) use (&$dispatched) {
            if (! self::automated()) {
                return;
            }
            self::markRunning(true);
            RunSeoChannelSyncJob::dispatch(
                'testimonials:sync-houzz-reviews',
                ['--browser-scrape' => true, '--only-new' => true],
                $site->id,
            );
            $dispatched[] = $site->slug;
        });

        return $dispatched;
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
     * activity page can list reviews of other pros too. The last path
     * segment must START with the brand (punctuation and spacing stripped),
     * which allows the suffix Houzz adds from the registered name —
     * "J. Peterson Design" matches "J-Peterson-Design-LLC-review" — but not
     * another business that merely contains the name
     * ("Atlas-GS-Construction-Partners-review").
     */
    public static function reviewUrlBelongsTo(string $url, string $brand): bool
    {
        $norm = fn (string $s) => (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s));
        $needle = $norm($brand);
        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));
        $last = $norm((string) (end($segments) ?: ''));

        return $needle !== ''
            && str_contains($norm(implode('/', $segments)), 'viewreview')
            && str_starts_with($last, $needle);
    }
}
