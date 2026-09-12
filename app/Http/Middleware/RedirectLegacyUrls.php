<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Support\Areas\RetiredAreaRedirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectLegacyUrls
{
    /**
     * Legacy URL redirects for SEO link equity preservation.
     *
     * Add old URLs here when they change to preserve search rankings.
     */
    protected array $redirects = [
        // Old URL => New URL
        '/testimonials' => '/reviews',
        '/gallery' => '/projects',
        '/portfolio' => '/projects',
        '/our-work' => '/projects',
        '/kitchen' => '/services/kitchen-remodeling',
        '/bathroom' => '/services/bathroom-remodeling',
        '/basement' => '/services/basement-remodeling',
        '/home-renovation' => '/services/home-remodeling',
        '/kitchens' => '/services/kitchen-remodeling',
        '/bathrooms' => '/services/bathroom-remodeling',
        '/basements' => '/services/basement-remodeling',
        '/about-us' => '/about',
        '/contact-us' => '/contact',
        '/get-quote' => '/contact',
        '/free-estimate' => '/contact',
        '/service-areas' => '/areas-served',
    ];

    /**
     * Pattern-based redirects for dynamic URLs.
     */
    protected array $patterns = [
        // Old pattern => New pattern (use {1}, {2} for capture groups)
        //
        // Targets are /areas-served/..., NOT /areas/... — /areas is a legacy
        // alias that unconditionally noindexes itself, so these 301s were
        // donating whatever equity old backlinks still carry to pages Google
        // is told to ignore. A redirect should land on the canonical URL.
        '#^/project/([^/]+)$#' => '/projects?project={1}',
        '#^/area/([^/]+)$#' => '/areas-served/{1}',
        '#^/city/([^/]+)$#' => '/areas-served/{1}',
        '#^/services/([^/]+)/([^/]+)$#' => '/areas-served/{2}/services/{1}',
    ];

    /**
     * Old-site paths with no successor page — WordPress archives and
     * taxonomies, old account pages, hero images of the previous theme —
     * answered 410 Gone. This runs for every request, live routes included,
     * so nothing here may ever match a page that exists (/faq and the
     * /feed/*.atom feeds are live). Anything with a successor belongs in
     * the maps above instead.
     */
    public const GONE = [
        '#^/\d{4}/\d{2}(/|$)#',          // /2024/01 date archives
        '#^/category/#',                  // /category/projects
        '#^/tag/#',
        '#^/author/#',
        '#^/index\.(php|html)$#',
        '#^/account/#',
        '#^/images/services/[^/]+-hero\.jpg$#',
        '#^/wp-content/#',
        '#^/wp-includes/#',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // This map is gs.construction's URL history (old WordPress paths,
        // renamed pages). Other tenants have their own routes at some of these
        // paths — /portfolio and /testimonials are live pages on jpeterson —
        // so the legacy redirects must not fire off-tenant.
        if (Site::current()->slug !== 'gsc') {
            return $next($request);
        }

        $path = '/'.ltrim($request->path(), '/');

        // Check exact redirects
        if (isset($this->redirects[$path])) {
            return redirect($this->redirects[$path], 301);
        }

        // Check pattern redirects
        foreach ($this->patterns as $pattern => $replacement) {
            if (preg_match($pattern, $path, $matches)) {
                $newPath = $replacement;

                // Replace capture groups
                foreach ($matches as $i => $match) {
                    if ($i === 0) {
                        continue;
                    }
                    $newPath = str_replace("{{$i}}", $match, $newPath);
                }

                return redirect($newPath, 301);
            }
        }

        // Remove trailing slashes (except for root)
        if ($path !== '/' && str_ends_with($path, '/')) {
            return redirect(rtrim($path, '/'), 301);
        }

        // A town the site no longer serves: /areas-served/{old-town}[/spoke]
        // goes to the nearest town still served (same spoke) or the areas
        // index, instead of the 404 Google keeps re-crawling. A served
        // town's own slug never matches here (target() returns null).
        if (preg_match('#^/areas-served/([^/]+)(/.*)?$#', $path, $m)) {
            $target = RetiredAreaRedirect::target($m[1], $m[2] ?? '');
            if ($target !== null) {
                return redirect($target, 301);
            }
        }

        // Paths from the previous site that have no successor: 410 Gone tells
        // Google to drop them, where a 404 keeps it coming back.
        foreach (self::GONE as $pattern) {
            if (preg_match($pattern, $path)) {
                abort(410);
            }
        }

        // Force lowercase URLs (except for Livewire routes which have case-sensitive filenames)
        // Livewire 3 uses /livewire/, Livewire 4 uses /livewire-{hash}/
        if (! str_starts_with($path, '/livewire')) {
            $lowercasePath = strtolower($path);
            if ($path !== $lowercasePath && $path !== '/') {
                $query = $request->getQueryString();
                $newUrl = $lowercasePath.($query ? '?'.$query : '');

                return redirect($newUrl, 301);
            }
        }

        return $next($request);
    }
}
