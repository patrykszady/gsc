<?php

/*
|--------------------------------------------------------------------------
| Signed, unauthenticated Yelp/Instagram remote-login viewer redirect
|--------------------------------------------------------------------------
|
| Ownership: this is the central-admin port of the legacy noVNC viewer (see
| Api\Admin\V1\PlatformsController::remoteLoginPayload()). Kept in its OWN
| file, required at the very end of web.php, so the boundary between "the
| rest of gsc's routes" and "this one central-admin-facing redirect" stays
| obvious at a glance instead of being buried mid-file.
|
| Deliberately OUTSIDE the 'auth' middleware group: the iframe that loads
| this URL is embedded on ss-systems' origin (the central admin), which has
| no gsc session cookie to present — a session-authed route would just
| break there. Protection here is the route SIGNATURE (minted server-side
| with a 15-minute TTL, see remoteLoginPayload()) plus 'noindex' — not a
| login.
|
| Reusing ResolveAdminSite unauthenticated is safe: its authorization check
| (`$user !== null && ! $user->canAccessSite($site)`) is a no-op when
| $request->user() is null, which it always is here since 'auth' never ran
| — the exact same reasoning PinAdminApiTenant's docblock relies on for the
| stateless API side of this same feature.
*/

use App\Http\Controllers\PlatformsViewerController;
use App\Http\Middleware\ResolveAdminSite;
use Illuminate\Support\Facades\Route;

Route::middleware(['signed', 'noindex', ResolveAdminSite::class])
    ->prefix('admin/{site}')
    ->where(['site' => '[a-z0-9\-]+\.[a-z0-9.\-]+'])
    ->name('admin.')
    ->group(function () {
        Route::get('/platforms/{provider}/viewer', [PlatformsViewerController::class, 'show'])
            ->whereIn('provider', ['yelp', 'instagram'])
            ->name('platforms.viewer');
    });
