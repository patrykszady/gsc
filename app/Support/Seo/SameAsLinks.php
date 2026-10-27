<?php

namespace App\Support\Seo;

use App\Models\Citation;
use App\Models\Site;

/**
 * Every profile URL this business's structured data should claim via
 * schema.org `sameAs`: the social profiles configured in config/socials.php
 * plus any business-directory citation that has gone live (Citation::
 * STATUS_LIVE with a real listing_url), deduped. One list, read by the
 * Organization, LocalBusiness and per-town HomeAndConstructionBusiness
 * nodes alike, so they can never drift apart on which directories they
 * claim — before this they each hand-maintained their own copy of the
 * six config('socials.*') URLs and none of them knew about a live
 * citation at all.
 */
final class SameAsLinks
{
    /**
     * @return list<string>
     */
    public static function urls(): array
    {
        $social = collect(config('socials', []))
            ->map(fn (array $profile) => $profile['url'] ?? null)
            ->filter();

        $citations = Citation::query()
            ->where('site_id', Site::current()->id)
            ->where('status', Citation::STATUS_LIVE)
            ->whereNotNull('listing_url')
            ->where('listing_url', '!=', '')
            ->pluck('listing_url');

        return $social->concat($citations)
            ->filter(fn ($url) => filled($url))
            ->unique()
            ->values()
            ->all();
    }
}
