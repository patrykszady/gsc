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

/**
 * Listings the site already has, from what Platforms and Social Media know:
 * the Houzz and Angi profiles whose reviews we import, the Yelp business
 * profile, the Facebook page behind the Meta connection. The Citations
 * board used to plan, fail or hand these to a person as if the listings
 * did not exist; they are matched here and read as live with the
 * listing's own URL, every time the board is synced or opened.
 */
class KnownListings
{
    /** Statuses a match may move to live — never a run in progress, a submission awaiting verification, or a deliberate decline. */
    protected const MOVABLE = [
        Citation::STATUS_PLANNED, Citation::STATUS_FAILED, Citation::STATUS_NEEDS_HUMAN,
        Citation::STATUS_UNREACHABLE, Citation::STATUS_NO_MECHANISM,
    ];

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
        $changed = 0;

        foreach (self::forCurrentSite() as $slug => $known) {
            $row = Citation::query()->where('site_id', Site::current()?->id)->where('slug', $slug)->first();
            if (! $row) {
                continue;
            }

            $dirty = false;
            if (blank($row->listing_url)) {
                $row->listing_url = $known['url'];
                $dirty = true;
            }
            if (in_array($row->status, self::MOVABLE, true)) {
                $row->status = Citation::STATUS_LIVE;
                $row->live_at ??= now();
                $row->human_reason = null;
                $row->note = 'Listed already — '.$known['source'].'.';
                $dirty = true;
            }
            if ($dirty) {
                $row->addLog('Matched from what Platforms already knows: '.$known['source'], 'sync');
                $row->save();
                $changed++;
            }
        }

        return $changed;
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
