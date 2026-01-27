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
        '/reviews' => '/testimonials',
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
        '#^/project/([^/]+)$#' => '/projects?project={1}',
        '#^/area/([^/]+)$#' => '/areas/{1}',
        '#^/city/([^/]+)$#' => '/areas/{1}',
        '#^/services/([^/]+)/([^/]+)$#' => '/areas/{2}/services/{1}',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
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
