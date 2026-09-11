<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * Session-less CSRF protection for the Platforms OAuth callbacks.
 *
 * gsc protects its /admin/{site}/platforms/{provider}/callback routes with
 * the 'auth' middleware — an admin session — see its routes/web.php. This
 * site has no local admin session at all: its entire /admin surface is a
 * stateless proxy to ss-systems' central admin (see AdminProxyController),
 * so that protection isn't available here.
 *
 * Instead, PlatformsController::oauthUrl() mints a short-lived, tamper-proof
 * "state" value via make() and passes it as the OAuth 'state' query param
 * to Google/Meta; routes/web.php's /admin-oauth/{provider}/callback route
 * validates it with verify() BEFORE ever exchanging a code, and rejects
 * anything expired, re-used-for-the-wrong-provider, or not minted by this
 * app (Crypt uses this app's APP_KEY, so it can't be forged from outside).
 *
 * This is the one deliberate divergence from gsc's OAuth flow — documented
 * here and in PlatformsController's class docblock.
 */
class OAuthState
{
    protected const TTL_MINUTES = 15;

    public static function make(string $provider): string
    {
        return Crypt::encryptString(json_encode([
            'provider' => $provider,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->timestamp,
        ]));
    }

    public static function verify(?string $state, string $provider): bool
    {
        if (! $state) {
            return false;
        }

        try {
            $payload = json_decode(Crypt::decryptString($state), true);
        } catch (\Throwable) {
            return false;
        }

        if (! is_array($payload)) {
            return false;
        }

        return ($payload['provider'] ?? null) === $provider
            && (int) ($payload['expires_at'] ?? 0) >= now()->timestamp;
    }
}
