<?php

namespace App\Http\Middleware;

use App\Models\Tracked404;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminating middleware that records 404 responses so we can later submit
 * persistent dead URLs to IndexNow for re-crawl/deindex.
 *
 * We use a middleware (not an exception reporter) because Laravel's default
 * exception handler filters NotFoundHttpException out of report callbacks.
 */
class Track404Responses
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($response->getStatusCode() !== 404) {
            return;
        }

        try {
            if ($request->isMethod('OPTIONS')) {
                return;
            }
            $path = '/' . ltrim($request->path(), '/');
            if ($path === '/' || strlen($path) > 500) {
                return;
            }
            // Skip noisy bot probes for common WordPress/PHP/env paths.
            if (preg_match('#\.(php|asp|aspx|env|git|sql|bak|cgi|jsp)$#i', $path)
                || str_contains($path, 'wp-')
                || str_contains($path, 'xmlrpc')
                || str_contains($path, '.well-known')
                || str_starts_with($path, '/admin')
                || str_starts_with($path, '/horizon')
                || str_starts_with($path, '/livewire')
                || str_starts_with($path, '/storage')) {
                return;
            }

            $row = Tracked404::firstOrNew(['path' => $path]);
            $row->referer = mb_substr((string) $request->headers->get('referer'), 0, 500);
            $row->user_agent = mb_substr((string) $request->userAgent(), 0, 500);
            $row->hit_count = ($row->hit_count ?? 0) + 1;
            if (! $row->exists) {
                $row->first_seen_at = now();
            }
            $row->last_seen_at = now();
            $row->save();
        } catch (\Throwable $t) {
            // Never let 404 logging break the response cycle.
        }
    }
}
