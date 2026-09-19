<?php

namespace App\Support\Seo;

use App\Services\GoogleSearchConsoleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What Google actually knows about our sitemaps.
 *
 * The nightly seo:gsc-submit-sitemaps run reports success into a log nobody
 * reads, so the only way to answer "is our sitemap on Search Console?" was to
 * open the Search Console UI or query the API by hand — the admin's SEO screen
 * never said a word about sitemaps. This turns sitemaps.list into a section of
 * the SEO snapshot, so the screen answers the question by itself.
 *
 * Kept BYTE-IDENTICAL in gsc and jpeterson-design (change it in one, copy it to
 * the other): the ss-systems central admin renders one Sitemaps card from this
 * shape for every tenant, so a field that exists on one site and not the other
 * renders an empty card for that tenant alone.
 *
 * The property is passed in rather than read from config, because that is the
 * one place the two sites genuinely differ — gsc resolves it through
 * SearchConsoleProperty, jpeterson-design reads config directly.
 */
final class SitemapStatus
{
    /** Remote call; the snapshot around it is cached for 15 minutes too. */
    public const CACHE_KEY = 'seo.sitemap-status';

    public const TTL_SECONDS = 900;

    /** A sitemap Google has not re-fetched in this long has stopped working. */
    public const STALE_AFTER_DAYS = 7;

    /**
     * @return array{state: string, connected: bool, property: ?string, error: ?string, checked_at: string, entries: array<int, array<string, mixed>>}
     */
    public static function snapshot(?string $property, bool $fresh = false): array
    {
        if ($fresh) {
            self::forget($property);
        }

        return Cache::remember(
            self::CACHE_KEY.':'.md5((string) $property),
            self::TTL_SECONDS,
            fn () => self::build($property),
        );
    }

    public static function forget(?string $property = null): void
    {
        Cache::forget(self::CACHE_KEY.':'.md5((string) $property));
    }

    /**
     * @return array{state: string, connected: bool, property: ?string, error: ?string, checked_at: string, entries: array<int, array<string, mixed>>}
     */
    protected static function build(?string $property): array
    {
        $service = app(GoogleSearchConsoleService::class);

        // Not connected is a setup state, not a failure: the card says "connect
        // Search Console" rather than showing a red error nobody caused.
        if (! $property || ! $service->isConfigured()) {
            return self::payload('unconfigured', $property, connected: false);
        }

        $sitemaps = $service->listSitemaps($property);

        if ($sitemaps === null) {
            $error = $service->getLastError();

            return self::payload('error', $property, error: (string) ($error['message'] ?? 'Search Console did not answer.'));
        }

        $entries = [];

        foreach ($sitemaps as $sitemap) {
            $lastDownloaded = $sitemap['lastDownloaded'] ?? null;

            // contents[] is per content-type (web, image, video); the submitted
            // count is their sum, and Google omits it entirely until it has
            // parsed the file at least once.
            $urls = 0;
            foreach ($sitemap['contents'] ?? [] as $content) {
                $urls += (int) ($content['submitted'] ?? 0);
            }

            $submitted = $sitemap['lastSubmitted'] ?? null;

            $entries[] = [
                'path' => (string) ($sitemap['path'] ?? ''),
                'type' => (string) ($sitemap['type'] ?? 'sitemap'),
                'last_submitted' => $submitted,
                'last_downloaded' => $lastDownloaded,
                // Human ages are built here, not in Blade: the admin view is
                // shared by every tenant and stays free of date formatting.
                'submitted_age' => $submitted ? Carbon::parse($submitted)->diffForHumans() : null,
                'downloaded_age' => $lastDownloaded ? Carbon::parse($lastDownloaded)->diffForHumans() : null,
                'age_days' => $lastDownloaded ? (int) Carbon::parse($lastDownloaded)->diffInDays(now()) : null,
                'errors' => (int) ($sitemap['errors'] ?? 0),
                'warnings' => (int) ($sitemap['warnings'] ?? 0),
                'is_pending' => (bool) ($sitemap['isPending'] ?? false),
                'is_index' => (bool) ($sitemap['isSitemapsIndex'] ?? false),
                'urls' => $urls,
            ];
        }

        return self::payload(self::state($entries), $property, entries: $entries);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{state: string, connected: bool, property: ?string, error: ?string, checked_at: string, entries: array<int, array<string, mixed>>}
     */
    protected static function payload(
        string $state,
        ?string $property,
        bool $connected = true,
        ?string $error = null,
        array $entries = [],
    ): array {
        return [
            'state' => $state,
            'connected' => $connected,
            'property' => $property ?: null,
            'error' => $error,
            'checked_at' => now()->toIso8601String(),
            'entries' => $entries,
        ];
    }

    /**
     * One word the admin turns into a status dot, decided here so both tenants
     * grade themselves the same way.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    protected static function state(array $entries): string
    {
        if ($entries === []) {
            return 'none';
        }

        foreach ($entries as $entry) {
            if ($entry['errors'] > 0) {
                return 'errors';
            }
        }

        foreach ($entries as $entry) {
            // Never fetched, or not fetched in a week: submitted once and then
            // forgotten looks identical to working until you check the date.
            if ($entry['age_days'] === null || $entry['age_days'] > self::STALE_AFTER_DAYS) {
                return 'stale';
            }

            if ($entry['warnings'] > 0) {
                return 'warnings';
            }
        }

        return 'ok';
    }
}
