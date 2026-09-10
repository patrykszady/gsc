<?php

namespace App\Support\Reviews;

use App\Jobs\RunSeoChannelSyncJob;
use App\Models\PlatformSetting;
use App\Models\Site;
use App\Models\Testimonial;
use App\Support\SiteConfig;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A review site we import from by reading its public profile page: Houzz,
 * Angi. Neither has an API, so a browser reads the page every week and new
 * reviews become testimonials.
 *
 * There is nothing to switch on. A site with a profile URL imports; a site
 * without one does not. The URL is the platform's link under Social Media →
 * profile links (one site-scoped PlatformSetting), and the Platforms page
 * shows it read-only beside the import status and last-run record.
 */
abstract class ReviewImport
{
    /** A run that never reports back stops showing as "running" after this long. */
    public const RUNNING_TTL_MINUTES = 20;

    /** The review_urls / socials key: 'houzz', 'angi'. */
    abstract public static function platform(): string;

    /** How the site names it to an operator. */
    abstract public static function label(): string;

    /** The artisan command that performs the import. */
    abstract public static function command(): string;

    /**
     * Options the weekly run passes to that command.
     *
     * @return array<string, mixed>
     */
    abstract protected static function commandOptions(): array;

    /** Every platform imported this way, in the order the admin lists them. */
    public static function sources(): array
    {
        return [HouzzReviews::class, AngiReviews::class];
    }

    public static function urlKey(): string
    {
        return 'socials.url.'.static::platform();
    }

    public static function lastRunKey(): string
    {
        return static::platform().'.reviews.last_run';
    }

    public static function profileUrl(): ?string
    {
        $stored = trim((string) PlatformSetting::get(static::urlKey()));
        if ($stored !== '') {
            return $stored;
        }

        // config/socials.php is the default site's own. A site that has not
        // set its own profile would otherwise inherit that one and import
        // another business's reviews as its own.
        if (! SiteConfig::owns('socials.'.static::platform().'.url')) {
            return null;
        }

        $url = trim((string) config('socials.'.static::platform().'.url', ''));

        return $url !== '' ? $url : null;
    }

    /** Imports run automatically for any site with a profile URL. */
    public static function automated(): bool
    {
        return static::profileUrl() !== null;
    }

    public static function save(?string $profileUrl): void
    {
        $url = trim((string) $profileUrl);
        PlatformSetting::put(static::urlKey(), $url !== '' ? $url : null);
    }

    /**
     * What the Platforms card shows for this platform.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $query = Testimonial::query()
            ->whereHas('reviewUrls', fn ($q) => $q->where('platform', static::platform()));
        $latest = (clone $query)->max('review_date');

        return [
            'profile_url' => static::profileUrl(),
            'reviews_count' => (clone $query)->count(),
            'latest_review_date' => $latest ? Carbon::parse($latest)->toDateString() : null,
            'last_run' => static::lastRun(),
            'running' => static::isRunning(),
        ];
    }

    /** Queue this platform's import for the current site. */
    public static function dispatchImport(?Site $site = null): void
    {
        $site ??= Site::current();

        static::markRunning(true);
        RunSeoChannelSyncJob::dispatch(static::command(), static::commandOptions(), $site->id);
    }

    /**
     * The weekly schedule: one queued import per active site with a profile
     * URL, run as that site. Returns the slugs dispatched.
     *
     * @return list<string>
     */
    public static function dispatchScheduledImports(): array
    {
        $dispatched = [];

        Tenancy::each(function (Site $site) use (&$dispatched) {
            if (! static::automated()) {
                return;
            }
            static::dispatchImport($site);
            $dispatched[] = $site->slug;
        });

        return $dispatched;
    }

    /** @param  array<string, int|string|null>  $summary */
    public static function recordRun(array $summary, ?string $error = null): void
    {
        PlatformSetting::put(static::lastRunKey(), (string) json_encode([
            'at' => now()->toIso8601String(),
            'error' => $error,
            ...$summary,
        ]));
        static::markRunning(false);
    }

    /** @return array<string, mixed>|null */
    public static function lastRun(): ?array
    {
        $raw = PlatformSetting::get(static::lastRunKey());
        $data = $raw ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    public static function markRunning(bool $running): void
    {
        $key = Tenancy::cacheKey(static::platform().'.reviews.running');
        $running ? Cache::put($key, true, now()->addMinutes(static::RUNNING_TTL_MINUTES)) : Cache::forget($key);
    }

    public static function isRunning(): bool
    {
        return (bool) Cache::get(Tenancy::cacheKey(static::platform().'.reviews.running'), false);
    }

    public static function brandName(): string
    {
        return (string) config('brand.name', config('app.name'));
    }

    /** Letters and digits only, for comparing a business name with a URL slug or page heading. */
    public static function normalizeName(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($value));
    }
}
