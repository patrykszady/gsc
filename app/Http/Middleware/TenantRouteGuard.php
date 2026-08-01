<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Support\ExclusivePaths;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 404s a tenant-exclusive path when it is requested on another tenant's host.
 *
 * routes/web.php registers one global route table, so without this every site
 * serves gs.construction's /compare, /permits, /costs, /trades and so on —
 * complete with GS content — on its own domain. That is both wrong for the
 * visitor and a duplicate-content problem for search.
 *
 * Implemented as a path guard rather than by splitting routes/web.php into
 * per-site files: it is a single reviewable list, it cannot break route
 * registration or `route:cache`, and gs.construction's routing is untouched.
 * Splitting the route files remains the cleaner end state once each tenant has
 * a real route set of its own.
 *
 * The ownership rules themselves live in App\Support\ExclusivePaths, so the
 * dev switcher and `php artisan sites:check` predict a 404 with exactly the
 * code that causes it. A second implementation would drift, and drift here
 * means a checker reporting green while the middleware 404s the visitor.
 */
class TenantRouteGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ExclusivePaths::allows(Site::current()->slug, $request->path())) {
            abort(404);
        }

        return $next($request);
    }
}
