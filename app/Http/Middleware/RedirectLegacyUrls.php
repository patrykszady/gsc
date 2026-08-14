<?php

namespace App\Http\Middleware;

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
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // This map is gs.construction's URL history (old WordPress paths,
        // renamed pages). Other tenants have their own routes at some of these
        // paths — /portfolio and /testimonials are live pages on jpeterson —
        // so the legacy redirects must not fire off-tenant.
        if (\App\Models\Site::current()->slug !== 'gsc') {
            return $next($request);
        }

        $path = '/' . ltrim($request->path(), '/');
        
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
                    if ($i === 0) continue;
                    $newPath = str_replace("{{$i}}", $match, $newPath);
                }
                
                return redirect($newPath, 301);
            }
        }
        
        // Remove trailing slashes (except for root)
        if ($path !== '/' && str_ends_with($path, '/')) {
            return redirect(rtrim($path, '/'), 301);
        }
        
        // Force lowercase URLs (except for Livewire routes which have case-sensitive filenames)
        // Livewire 3 uses /livewire/, Livewire 4 uses /livewire-{hash}/
        if (!str_starts_with($path, '/livewire')) {
            $lowercasePath = strtolower($path);
            if ($path !== $lowercasePath && $path !== '/') {
                $query = $request->getQueryString();
                $newUrl = $lowercasePath . ($query ? '?' . $query : '');
                return redirect($newUrl, 301);
            }
        }

        return $next($request);
    }
}
