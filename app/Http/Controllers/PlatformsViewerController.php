<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Redirect target for the signed noVNC viewer URL minted by
 * Api\Admin\V1\PlatformsController::remoteLoginPayload(). See
 * routes/platforms-viewer.php for why this route sits outside 'auth'.
 *
 * The real viewer URL (embeds the remote-login session's one-time VNC
 * password) is never handed to the browser directly — it's cached
 * server-side by the /remote-login/start, /remote-login/reset and
 * /remote-login/poll management-API endpoints, keyed by provider. This
 * controller's only job, once the 'signed' middleware has already
 * confirmed the signature is valid and unexpired, is to look that cached
 * URL up and send the browser on to it.
 */
class PlatformsViewerController extends Controller
{
    public function show(string $site, string $provider): RedirectResponse
    {
        $url = Cache::get("platforms.remote_login_url.{$provider}");

        abort_if($url === null, 410, 'This remote-login session has ended. Start a new one from the Platforms screen.');

        return redirect()->away($url);
    }
}
