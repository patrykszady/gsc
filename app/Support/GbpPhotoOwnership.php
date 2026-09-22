<?php

namespace App\Support;

/**
 * Who uploads this site's photos to its Google Business Profile
 * (2026-09-22): this app's own observer + jobs ('site', the default), or
 * the central admin (GBP_PHOTOS_OWNED_BY=ss-systems), which sends them
 * through the /api/admin/v1/platforms/gbp/media pass-through and keeps its
 * own ledger. With the central admin owning them every site-side path is
 * inert — ProjectImageObserver, ProjectObserver, the two queued jobs and
 * google-business-profile:sync — so a photo is never sent twice. The
 * pass-through itself is never gated: it is how those uploads arrive.
 *
 * A static read of config rather than a method on
 * GoogleBusinessProfileService, on purpose: tests mock that service
 * wholesale, and an observer calling an unexpected method on a Mockery
 * mock is an error.
 */
class GbpPhotoOwnership
{
    public static function ownedHere(): bool
    {
        return config('services.google.business_profile.photos_owned_by', 'site') !== 'ss-systems';
    }
}
