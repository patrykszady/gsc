<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins every /api/admin/v1 request to the gsc tenant — the API's equivalent
 * of what ResolveAdminSite does from the {site} URL segment on the legacy
 * admin. This API is called server-to-server by ss-systems for exactly one
 * site's data; there is no meaningful Host or URL segment to resolve from,
 * so the tenant is a fixed constant.
 *
 * Runs after admin.api.auth. The content models are untouched:
 * BelongsToSite's SiteScope global scope filters every query for free once
 * Site::setCurrent() is pinned (same mechanism ResolveAdminSite uses).
 * Hardcoding 'gsc' also guarantees the leftover, inactive 'jpeterson'
 * tenant row is unreachable through this API — the real J. Peterson app is
 * standalone and has its own API. And it makes the pin independent of
 * config('sites.default'), which console/queue code happens to fall back
 * to today but is not a guarantee.
 */
class PinAdminApiTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        Site::setCurrent(Site::query()->where('slug', 'gsc')->firstOrFail());

        return $next($request);
    }
}
