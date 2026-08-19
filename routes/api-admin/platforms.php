<?php

// Management-API surface for the 'platforms' ops domain — filled in by its port.
// Inherits the /api/admin/v1 prefix + auth/tenant middleware from the
// enclosing group in routes/api.php.

use App\Http\Controllers\Api\Admin\V1\PlatformsController;
use Illuminate\Support\Facades\Route;

Route::prefix('platforms')->group(function () {
    Route::get('status', [PlatformsController::class, 'status']);

    // Read-only Yelp reviews summary (DB only, no yelp.com round trip) and
    // the on-demand sitemap re-submit.
    Route::get('yelp/reviews-summary', [PlatformsController::class, 'yelpReviewsSummary']);
    Route::post('gsc/submit-sitemaps', [PlatformsController::class, 'submitGscSitemaps']);

    // ---- Yelp: credentials, session, cookie injection, auto-login ----
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
