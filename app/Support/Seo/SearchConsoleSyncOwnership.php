<?php

namespace App\Support\Seo;

/**
 * Who runs this site's Search Console sync (2026-09-22): this app's own
 * three-hourly schedule ('site', the default), or the central admin
 * (GSC_SYNC_OWNED_BY=ss-systems), if/when ss.systems fans the shared kit's
 * sync out to every tenant itself instead. Mirrors
 * App\Support\GbpPhotoOwnership's shape exactly.
 *
 * Only the SCHEDULE reads this — see routes/console.php's seo:gsc-sync
 * entry. The command itself and the admin's on-demand "sync now"
 * (PlatformsController::syncGsc()) are left ungated: a manual trigger must
 * never be silently swallowed just because the schedule has moved
 * elsewhere.
 *
 * A static read of config rather than a method on GoogleSearchConsoleService,
 * on purpose: the same reason GbpPhotoOwnership is a static read — tests that
 * mock the service wholesale would otherwise have to stub an unrelated method
 * just to satisfy this check.
 */
class SearchConsoleSyncOwnership
{
    public static function ownedHere(): bool
    {
        return config('services.google.search_console.sync_owned_by', 'site') !== 'ss-systems';
    }
}
