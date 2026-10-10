<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 301s away the hashed Livewire pagination query param an old build of this
 * site used to emit (e.g. `?08c56c61_page=2`).
 *
 * Multiple <livewire:projects-grid> instances on one page (area-page.blade.php
 * renders three) used to need a page name unique per instance, and whatever
 * scheme generated that hash is long gone: ProjectsGrid::render() and
 * ProjectPhotosGallery::pageName() both use plain, readable names today
 * ('page', 'page-area-{id}') — never an 8-hex-char prefix. Google still
 * crawled and indexed thousands of the old URLs, and robots.txt disallowed
 * `/*_page=` to stop the re-crawling, which backfired: a URL Google already
 * has indexed cannot be re-crawled to discover the rel=canonical pointing at
 * the bare page, so Search Console reports "Indexed, though blocked by
 * robots.txt" instead of letting the redirect fold it into the canonical.
 *
 * A straight 301 that drops only the stale param (every other query param
 * survives) lets Google do that itself. Matching on "ends with _page" rather
 * than the literal 8-hex-char shape also catches any other hash variant
 * Google remembers, since nothing in the current app ever produces a
 * `*_page` key other than the literal `page` — which this never touches, so
 * `/projects?page=2` keeps working untouched.
 */
class RedirectLegacyPaginationParams
{
    public function handle(Request $request, Closure $next): Response
    {
        $query = $request->query();

        $legacyKeys = array_values(array_filter(
            array_keys($query),
            fn (string $key): bool => $key !== 'page' && str_ends_with($key, '_page'),
        ));

        if ($legacyKeys === []) {
            return $next($request);
        }

        $remaining = array_diff_key($query, array_flip($legacyKeys));

        $path = '/'.ltrim($request->path(), '/');
        $location = $remaining === [] ? $path : $path.'?'.http_build_query($remaining);

        return redirect($location, 301);
    }
}
