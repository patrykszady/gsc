<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureUtmParameters
{
    /**
     * UTM parameters to capture from URL into session.
     */
    protected array $utmParameters = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'gclid',     // Google Ads click ID
        'fbclid',    // Facebook click ID
        'msclkid',   // Microsoft/Bing click ID
    ];

    /**
     * Handle an incoming request.
     * Captures UTM parameters and stores them in session for later use.
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach ($this->utmParameters as $param) {
            if ($request->has($param) && ! session()->has($param)) {
                session([$param => $request->input($param)]);
            }
        }
        
        // Also store the original landing page for attribution
        if (! session()->has('landing_page')) {
            session(['landing_page' => $request->fullUrl()]);
        }

        return $next($request);
    }
}
