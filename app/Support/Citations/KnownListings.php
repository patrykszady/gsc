<?php

namespace App\Support\Citations;

use App\Models\Citation;
use App\Models\PlatformSetting;
use App\Models\Site;
use App\Services\MetaSocialService;
use App\Services\YelpBusinessService;
use App\Support\Reviews\AngiReviews;
use App\Support\Reviews\HouzzReviews;
use App\Support\SiteConfig;
use SsSystems\Platform\Citations\KnownListingsReconciler;

/**
 * Listings the site already has, from what Platforms and Social Media know:
 * the Houzz and Angi profiles whose reviews we import, the Yelp business
 * profile, the Facebook page behind the Meta connection. The Citations
 * board used to plan, fail or hand these to a person as if the listings
 * did not exist; they are matched here and read as live with the
 * listing's own URL, every time the board is synced or opened.
 *
 * `reconcile()`'s loop moved to the kit's `Citations\
 * KnownListingsReconciler` (citations-admin-actions, 2026-09-27 —
 * verbatim; see that class's own docblock and citations.md #5). This
 * static method is now a one-line wrapper so `citations:sync`'s existing
 * `KnownListings::reconcile()` call keeps working unchanged;
 * `CitationsAdminActions` (the API's own path) constructs its own
 * `KnownListingsReconciler` instance the same way. `forCurrentSite()` —
 * genuinely per-site content — is unchanged below; `SiteKnownListingsSource`
 * is the thin adapter that hands it to the kit.
 */
class KnownListings
{
    /**
     * @return array<string, array{url: string, source: string}> keyed by citation slug
     */
    public static function forCurrentSite(): array
    {
        $found = [];

        foreach (['houzz' => HouzzReviews::class, 'angi' => AngiReviews::class] as $slug => $import) {
            if ($url = $import::profileUrl()) {
                $count = (int) ($import::status()['reviews_count'] ?? 0);
                $found[$slug] = ['url' => $url, 'source' => $count > 0 ? "Platforms: {$count} reviews imported from this profile" : 'Platforms: profile connected'];
            }
        }

        if ($url = self::socialUrl('yelp')) {
            $found['yelp'] = ['url' => $url, 'source' => app(YelpBusinessService::class)->isConfigured() ? 'Platforms: Yelp for Business account connected' : 'Social Media: profile link'];
        }

        $meta = app(MetaSocialService::class)->getCredentials();
        if (! empty($meta['token']) && ! empty($meta['page_id'])) {
            $found['facebook'] = [
                'url' => self::socialUrl('facebook') ?? 'https://www.facebook.com/'.$meta['page_id'],
                'source' => 'Platforms: Facebook page '.($meta['page_name'] ?: $meta['page_id']).' connected',
            ];
        } elseif ($url = self::socialUrl('facebook')) {
            $found['facebook'] = ['url' => $url, 'source' => 'Social Media: page link'];
        }

        return $found;
    }

    /**
     * Bring the board in line with what is known. A listing URL fills an
     * empty one; a matched row that was planned, failed or waiting on a
     * person becomes live. Returns how many rows changed.
     */
    public static function reconcile(): int
    {
        $siteId = Site::current()?->id;

        return (new KnownListingsReconciler(new SiteKnownListingsSource, fn () => Citation::query()->where('site_id', $siteId), 'Platforms'))->reconcile();
    }

    /** The profile link Social Media holds for a platform, or the site's own configured one. */
    protected static function socialUrl(string $platform): ?string
    {
        $stored = trim((string) PlatformSetting::get('socials.url.'.$platform));
        if ($stored !== '') {
            return $stored;
        }
        if (class_exists(SiteConfig::class) && ! SiteConfig::owns('socials.'.$platform.'.url')) {
            return null;
        }
        $url = trim((string) config('socials.'.$platform.'.url', ''));

        return $url !== '' ? $url : null;
    }
}
