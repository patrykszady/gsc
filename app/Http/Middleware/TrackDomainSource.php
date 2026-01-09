<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackDomainSource
{
    /**
     * Handle an incoming request.
     * 
     * Tracks which domain the user entered from (for GA) and shares
     * domain-specific SEO configuration with views.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $this->normalizeHost($request->getHost());
        $primaryDomain = config('services.domains.primary', 'gs.construction');
        $alternateDomains = config('services.domains.alternates', []);
        
        // Check if request came from an alternate domain
        $domainConfig = $alternateDomains[$host] ?? null;
        
        if ($domainConfig) {
            // User is on an alternate domain - store for session tracking
            if (!session()->has('entry_domain')) {
                session([
                    'entry_domain' => $host,
                    'entry_domain_source' => $domainConfig['source'],
                    'entry_domain_config' => $domainConfig,
                ]);
            }
            
            // Share with all views
            view()->share('domainSource', $domainConfig['source']);
            view()->share('domainSeoFocus', $domainConfig['seo_focus']);
            view()->share('domainConfig', $domainConfig);
            view()->share('isAlternateDomain', true);
            
            // Set canonical URL to primary domain
            view()->share('canonicalDomain', $primaryDomain);
        } else {
            // Main domain - check if user originally came from alternate domain
            $entrySource = session('entry_domain_source');
            $entryConfig = session('entry_domain_config');
            
            view()->share('domainSource', $entrySource ?? 'direct');
            view()->share('domainSeoFocus', $entryConfig['seo_focus'] ?? null);
            view()->share('domainConfig', $entryConfig);
            view()->share('isAlternateDomain', false);
            view()->share('canonicalDomain', $primaryDomain);
        }
        
        return $next($request);
    }

    /**
     * Normalize the host by removing www. prefix.
     */
    protected function normalizeHost(string $host): string
    {
        return preg_replace('/^www\./', '', strtolower($host));
    }
}
