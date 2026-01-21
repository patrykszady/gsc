<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Detect visitor country using Cloudflare's CF-IPCountry header.
 * 
 * This middleware sets the visitor's country code in the session and shares
 * it with all views. Used to:
 * - Only load Google Analytics for US visitors (GDPR/privacy compliance)
 * - Optionally require Turnstile verification for non-US visitors
 * 
 * Cloudflare automatically adds the CF-IPCountry header with ISO 3166-1 alpha-2 codes.
 * @see https://developers.cloudflare.com/fundamentals/reference/http-request-headers/#cf-ipcountry
 */
class DetectCountry
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get country from Cloudflare header (set automatically for all Cloudflare-proxied requests)
        // Falls back to 'XX' (unknown) if not behind Cloudflare or header not present
        $country = $request->header('CF-IPCountry', 'XX');
        
        // Store in session for persistence across requests
        session(['visitor_country' => $country]);
        
        // Determine if visitor is from the US (includes US territories)
        // Default to US when country is unknown ('XX') or header missing - better to track than miss visits
        // Only exclude visitors we KNOW are outside the US
        $usCountries = ['US', 'PR', 'VI', 'GU', 'AS', 'MP'];
        $isUS = in_array($country, $usCountries) || $country === 'XX';
        
        // Share with all views
        View::share('visitorCountry', $country);
        View::share('isUSVisitor', $isUS);
        
        // Also set as request attribute for controllers/services
        $request->attributes->set('visitor_country', $country);
        $request->attributes->set('is_us_visitor', $isUS);
        
        return $next($request);
    }
}
