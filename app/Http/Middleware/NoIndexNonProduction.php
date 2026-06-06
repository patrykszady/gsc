<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends X-Robots-Tag: noindex, nofollow for any non-production environment
 * or dev/staging hostname.
 *
 * This stops staging mirrors such as dev.gs.construction from being indexed
 * by Google and competing with the canonical production site. robots.txt
 * Disallow only blocks crawling — it does not prevent indexing of URLs that
 * Google already discovered — so an explicit header is required.
 *
 * The canonical production host (from config('app.url')) running in the
 * production environment is never affected.
 */
class NoIndexNonProduction
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldNoIndex($request)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow', true);
        }

        return $response;
    }

    protected function shouldNoIndex(Request $request): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        $host = strtolower($request->getHost());

        foreach (['dev.', 'staging.', 'stage.', 'test.', 'preview.'] as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
