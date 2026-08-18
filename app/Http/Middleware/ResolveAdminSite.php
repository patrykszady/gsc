<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Support\SiteConfig;
use App\Support\Theme;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the site an admin request is *operating on*, taken from the URL:
 *
 *     gs.construction/admin/jpeterson-design.com/projects
 *                      ^^^^^^^^^^^^^^^
 *
 * This is separate from ResolveSite, which resolves the tenant from the HTTP
 * host. On the admin hub the host identifies the hub, not the data being
 * edited — so the path segment wins.
 *
 * URL::defaults() is what keeps this cheap: every existing route('admin.*')
 * call fills the {site} parameter automatically, so none of the ~19 admin
 * components or their views needed editing.
 */
class ResolveAdminSite
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->route('site') ?: $this->keyFromPath($request);

        if (is_string($key) && $key !== '') {
            $site = Site::forHost($key)
                ?? Site::query()->where('slug', $key)->first()
                ?? Site::query()->where('primary_host', $key)->first();

            abort_if($site === null, 404, "Unknown site [{$key}].");

            // AUTHORIZATION. Until this existed the only guard on
            // /admin/{site}/… was `auth`, so any logged-in user could swap the
            // segment and read another tenant's projects, leads and contact
            // submissions. A user scoped to a site may only administer that
            // site; site_id NULL is a platform admin and passes.
            //
            // Enforced here rather than in the route group because this
            // middleware is also registered as Livewire persistent middleware,
            // so it covers the update POSTs too — a check that only ran on the
            // initial GET would leave every subsequent interaction unguarded.
            $user = $request->user();
            abort_if(
                $user !== null && ! $user->canAccessSite($site),
                403,
                'Your account does not have access to this site.',
            );

            Site::setCurrent($site);
            Theme::apply($site);
            SiteConfig::applyRuntime($site);
        }

        // Keeps generated admin URLs pointing at the site being administered.
        URL::defaults(['site' => $key ?: Site::current()->primary_host]);

        return $next($request);
    }

    /**
     * Fall back to reading the segment straight off the path.
     *
     * Registered as Livewire persistent middleware, this also runs for POSTs to
     * /livewire/update, where the {site} route parameter is not guaranteed to be
     * bound. Livewire sends the originating page URL, so the segment is still
     * recoverable — and relying on the path is safer than depending on how
     * Livewire happens to rebuild the route.
     */
    protected function keyFromPath(Request $request): ?string
    {
        foreach ([$request->path(), (string) $request->header('Referer'), (string) $request->input('components.0.snapshot')] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if (preg_match('#/?admin/([a-z0-9\-]+\.[a-z0-9.\-]+)#i', $candidate, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
