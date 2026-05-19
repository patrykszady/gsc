<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends X-Robots-Tag: noindex, nofollow on the response.
 *
 * Use on admin / authenticated / private routes so search engines
 * drop them from the index even when they discover the URL via a
 * backlink or referrer (robots.txt Disallow blocks crawling but
 * does NOT prevent indexing of known URLs).
 */
class NoIndexHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow', true);

        return $response;
    }
}
