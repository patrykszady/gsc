<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token guard for POST /api/lead-filters/sync — the only caller is
 * the peer site's own SyncLeadFilterToPeer job. Same shape as
 * AuthenticateAdminApi: one trusted server-to-server caller, so a
 * constant-time comparison against a single shared token is enough.
 *
 * The token is deliberately its OWN config value (services.lead_filter_sync.
 * token), not admin_api.token — this endpoint is reachable by the peer site
 * itself, not by ss-systems, and the two should be free to rotate
 * independently.
 */
class AuthenticateLeadFilterSync
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.lead_filter_sync.token');

        if ($expected === '') {
            return response()->json([
                'message' => 'Lead-filter sync is not enabled on this server.',
            ], 503);
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return $next($request);
    }
}
