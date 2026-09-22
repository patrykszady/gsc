<?php

// Management-API surface for the 'platforms' ops domain — filled in by its port.
// Inherits the /api/admin/v1 prefix + auth/tenant middleware from the
// enclosing group in routes/api.php.

use App\Http\Controllers\Api\Admin\V1\PlatformsController;
use App\Http\Controllers\Api\Admin\V1\PlatformsHiveController;
use Illuminate\Support\Facades\Route;

Route::prefix('platforms')->group(function () {
    Route::get('status', [PlatformsController::class, 'status']);

    // hive.contractors: the CRM the site's leads go to, and whose connected
    // mailboxes it reads for email inquiries (PlatformsHiveController).
    Route::get('hive', [PlatformsHiveController::class, 'show']);
    Route::post('hive/credentials', [PlatformsHiveController::class, 'saveCredentials']);
    Route::delete('hive', [PlatformsHiveController::class, 'disconnect']);
    Route::put('hive/mailboxes', [PlatformsHiveController::class, 'saveMailboxes']);
    Route::post('hive/mailboxes/read', [PlatformsHiveController::class, 'readNow']);

    // Read-only Yelp reviews summary (DB only, no yelp.com round trip) and
    // the on-demand sitemap re-submit.
    Route::get('yelp/reviews-summary', [PlatformsController::class, 'yelpReviewsSummary']);
    Route::post('gsc/submit-sitemaps', [PlatformsController::class, 'submitGscSitemaps']);
    // On-demand "sync now": queues seo:gsc-sync rather than waiting for the
    // schedule's next three-hour tick.
    Route::post('gsc/sync', [PlatformsController::class, 'syncGsc']);

    // ---- Scraped review imports (Houzz, Angi): import now. Each profile URL is
    // that platform's link on the Social Media page — social-media/urls — and the
    // weekly import is automatic. ----
    Route::post('{platform}/reviews/sync', [PlatformsController::class, 'syncPlatformReviews'])
        ->whereIn('platform', ['houzz', 'angi']);

    // ---- Yelp: credentials, session, cookie injection, auto-login ----
    // This site's own Google OAuth client (client id + secret, or the JSON
    // Google Cloud Console downloads) — what Business Profile and Search
    // Console sign in with. Per site: never shared between sites.
    Route::post('google/credentials', [PlatformsController::class, 'saveGoogleCredentials']);
    Route::delete('google/credentials', [PlatformsController::class, 'clearGoogleCredentials']);

    // ---- SEO sources: Bing, Clarity, PageSpeed, DataForSEO ----
    // Same shape as google/credentials above: validate, write only the
    // fields actually sent (never overwrite a stored secret with a blank
    // re-submit), return the fresh status block, never the raw value.
    // DataForSEO's is written by ss.systems provisioning the tenant's
    // share of its own metered account, not typed by this site's owner —
    // see App\Support\Seo\DataForSeoSettings — but the endpoint shape is
    // identical so the site never needs to know the difference.
    Route::post('bing/credentials', [PlatformsController::class, 'saveBingCredentials']);
    Route::delete('bing/credentials', [PlatformsController::class, 'clearBingCredentials']);
    Route::post('clarity/credentials', [PlatformsController::class, 'saveClarityCredentials']);
    Route::delete('clarity/credentials', [PlatformsController::class, 'clearClarityCredentials']);
    Route::post('pagespeed/credentials', [PlatformsController::class, 'savePagespeedCredentials']);
    Route::delete('pagespeed/credentials', [PlatformsController::class, 'clearPagespeedCredentials']);
    Route::post('dataforseo/credentials', [PlatformsController::class, 'saveDataForSeoCredentials']);
    Route::delete('dataforseo/credentials', [PlatformsController::class, 'clearDataForSeoCredentials']);
    // Run seo:credentials-import-from-env over the API, per source, from the
    // SEO screen's Connect Services modal — see SeoCredentialsImport.
    Route::post('seo-credentials/import', [PlatformsController::class, 'importSeoCredentialsFromEnv']);

    // Which Business Profile listing this site publishes to. The ids only
    // exist after the OAuth grant, so they are discovered here and stored in
    // platform_settings rather than asked for as env values nobody can edit.
    Route::get('gbp/listings', [PlatformsController::class, 'gbpListings']);
    // One listing's Google reviews, for the central admin's per-market import.
    Route::get('gbp/reviews', [PlatformsController::class, 'gbpReviews']);
    // List/upload/delete a project photo on one listing, for the central
    // admin's per-market photo pass-through.
    Route::get('gbp/media', [PlatformsController::class, 'gbpListMedia']);
    Route::post('gbp/media', [PlatformsController::class, 'uploadGbpMedia']);
    Route::delete('gbp/media', [PlatformsController::class, 'deleteGbpMedia']);
    Route::post('gbp/listing', [PlatformsController::class, 'saveGbpListing']);
    Route::post('gbp/publishing', [PlatformsController::class, 'saveGbpPublishing']);
    Route::post('yelp/credentials', [PlatformsController::class, 'saveYelpCredentials']);
    Route::delete('yelp/credentials/password', [PlatformsController::class, 'clearYelpPassword']);
    Route::post('yelp/session/check', [PlatformsController::class, 'checkYelpSession']);
    Route::post('yelp/cookies/import', [PlatformsController::class, 'importYelpCookies']);
    Route::delete('yelp/cookies', [PlatformsController::class, 'clearYelpCookies']);
    Route::post('yelp/auto-login/settings', [PlatformsController::class, 'saveYelpAutoLoginSettings']);
    Route::post('yelp/auto-login/run', [PlatformsController::class, 'runYelpAutoLogin']);

    // ---- Yelp: remote-login viewer (Xvfb + noVNC). Start/reset return a
    // signed URL to gsc's unauthenticated viewer redirect — see
    // routes/platforms-viewer.php — never the raw noVNC URL.
    Route::post('yelp/remote-login/start', [PlatformsController::class, 'startYelpRemoteLogin']);
    Route::post('yelp/remote-login/poll', [PlatformsController::class, 'pollYelpRemoteLogin']);
    Route::post('yelp/remote-login/stop', [PlatformsController::class, 'stopYelpRemoteLogin']);
    Route::post('yelp/remote-login/reset', [PlatformsController::class, 'resetYelpProfile']);
    Route::post('yelp/remote-login/report-error', [PlatformsController::class, 'reportYelpRemoteError']);

    // ---- Instagram (Puppeteer profile, used for location-tagging) ----
    Route::post('instagram/session/verify', [PlatformsController::class, 'verifyInstagramSession']);
    Route::post('instagram/remote-login/start', [PlatformsController::class, 'startInstagramRemoteLogin']);
    Route::post('instagram/remote-login/poll', [PlatformsController::class, 'pollInstagramRemoteLogin']);
    Route::post('instagram/remote-login/stop', [PlatformsController::class, 'stopInstagramRemoteLogin']);
    Route::post('instagram/remote-login/reset', [PlatformsController::class, 'resetInstagramProfile']);
    Route::post('instagram/remote-login/report-error', [PlatformsController::class, 'reportInstagramRemoteError']);

    // ---- Meta: on-demand test post (creates, never publishes, a container) ----
    Route::post('meta/test-connection', [PlatformsController::class, 'testMetaConnection']);

    Route::get('{provider}/oauth-url', [PlatformsController::class, 'oauthUrl'])
        ->whereIn('provider', ['gbp', 'gsc', 'meta']);

    Route::delete('{provider}', [PlatformsController::class, 'disconnect'])
        ->whereIn('provider', ['gbp', 'gsc', 'meta']);
});
