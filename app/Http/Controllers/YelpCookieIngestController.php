<?php

namespace App\Http\Controllers;

use App\Jobs\VerifyYelpCookies;
use App\Support\YelpCookieJar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives a Yelp cookie jar from the browser extension.
 *
 * Why this exists: biz.yelp.com sits behind DataDome, which blocks scripted
 * logins from a datacentre IP. The owner's own browser has no such problem —
 * it is a real person on a residential connection. So instead of fighting the
 * bot wall, the extension hands us the session the owner already has, and a
 * background job injects it into the automation profile.
 *
 * Called by an MV3 service worker, which has no session and no CSRF token:
 * auth is a bearer token, compared in constant time. The route is CSRF-exempt
 * (bootstrap/app.php) but stays in the `web` group so ResolveSite still runs.
 */
class YelpCookieIngestController extends Controller
{
    /** Reject absurd payloads before json_decode. A real jar is ~20KB. */
    protected const MAX_BYTES = 512 * 1024;

    /**
     * Self-pairing for the browser extension.
     *
     * Reached only through the session-authed admin group: if you can load
     * the platforms page, you are entitled to the token — so the extension's
     * content script fetches this same-origin and configures itself. The
     * first pairing mints the token; manual copy-paste (which failed twice in
     * its first five minutes of real use) is no longer part of the flow.
     */
    public function pairing(Request $request): JsonResponse
    {
        $token = \App\Models\PlatformSetting::get(\App\Services\YelpBusinessService::SETTING_INGEST_TOKEN);

        if (! $token) {
            $token = \Illuminate\Support\Str::random(48);
            \App\Models\PlatformSetting::put(\App\Services\YelpBusinessService::SETTING_INGEST_TOKEN, $token);
            Log::channel('yelp')->info('Yelp extension token minted via self-pairing', [
                'user_id' => $request->user()?->id,
            ]);
        }

        Cache::put('yelp.extension_paired_at', now()->toIso8601String(), now()->addDays(60));

        return response()->json([
            'ok' => true,
            'serverUrl' => $request->getSchemeAndHttpHost(),
            'token' => $token,
        ]);
    }

    public function __invoke(Request $request): JsonResponse
    {
        // Two sources, either works. The admin-generated token
        // (PlatformSetting, rotatable from /admin/platforms without a deploy)
        // is the friendly path; the env one is for headless setup. Read via
        // config(), never env() — `php artisan config:cache` runs on deploy
        // and env() returns null there.
        $expected = (string) (
            \App\Models\PlatformSetting::get(\App\Services\YelpBusinessService::SETTING_INGEST_TOKEN)
                ?: config('services.yelp.business.cookie_ingest_token')
        );

        if ($expected === '') {
            return response()->json([
                'ok' => false,
                'error' => 'Cookie ingest is not enabled on this server.',
            ], 503);
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            // Deliberately vague, and rate-limited by the throttle middleware
            // on the route — this endpoint is reachable from the internet.
            Log::channel('yelp')->warning('Yelp cookie ingest: bad token', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['ok' => false, 'error' => 'Unauthorized.'], 401);
        }

        if (strlen((string) $request->getContent()) > self::MAX_BYTES) {
            return response()->json(['ok' => false, 'error' => 'Payload too large.'], 413);
        }

        $payload = $request->input('cookies', $request->json()->all());
        $result = YelpCookieJar::normalize($payload);
        $cookies = $result['cookies'];
        $stats = $result['stats'];

        if ($cookies === []) {
            Log::channel('yelp')->warning('Yelp cookie ingest: nothing usable in payload', $stats);

            return response()->json([
                'ok' => false,
                'error' => 'No usable yelp.com cookies in payload.',
                'stats' => $stats,
            ], 422);
        }

        // A jar without a session cookie will fail 90 seconds into a Chromium
        // run. Say so now, to the extension, where it can be shown to the user.
        if (! YelpCookieJar::looksAuthenticated($cookies)) {
            Log::channel('yelp')->warning('Yelp cookie ingest: no session cookie present', $stats);

            return response()->json([
                'ok' => false,
                'error' => 'Cookies received but none is a Yelp session cookie — are you logged in to biz.yelp.com?',
                'stats' => $stats,
            ], 422);
        }

        YelpCookieJar::write($cookies);

        Cache::put('yelp.cookies_ingested_at', now()->toIso8601String(), now()->addDays(60));
        Cache::put('yelp.cookies_ingested_stats', $stats, now()->addDays(60));

        Log::channel('yelp')->info('Yelp cookie ingest: jar written from browser extension', $stats);

        // Verification launches Chromium (up to 90s) — far too slow to hold the
        // extension's request open, so it runs on media-sync where the single
        // worker also serialises it against the upload jobs.
        VerifyYelpCookies::dispatch()->onQueue('media-sync');

        return response()->json([
            'ok' => true,
            'stored' => count($cookies),
            'stats' => $stats,
            'message' => 'Cookies stored; verifying session in the background.',
        ]);
    }
}
