<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CacheStaticAssets
{
    /**
     * Cache durations in seconds for different asset types.
     */
    protected array $cacheDurations = [
        'image' => 31536000,  // 1 year for images
        'font' => 31536000,   // 1 year for fonts
        'css' => 604800,      // 1 week for CSS
        'js' => 604800,       // 1 week for JS
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only apply to successful GET requests
        if ($request->method() !== 'GET' || $response->getStatusCode() !== 200) {
            return $response;
        }

        $contentType = $response->headers->get('Content-Type', '');
        $cacheDuration = $this->getCacheDuration($contentType, $request->path());

        if ($cacheDuration > 0) {
            $response->headers->set('Cache-Control', "public, max-age={$cacheDuration}, immutable");
            $response->headers->set('Vary', 'Accept-Encoding');
        }

        return $response;
    }

    /**
     * Get cache duration based on content type or path.
     */
    protected function getCacheDuration(string $contentType, string $path): int
    {
        // Images
        if (str_starts_with($contentType, 'image/') || preg_match('/\.(jpg|jpeg|png|gif|webp|svg|ico)$/i', $path)) {
            return $this->cacheDurations['image'];
        }

        // Fonts
        if (preg_match('/\.(woff2?|ttf|otf|eot)$/i', $path)) {
            return $this->cacheDurations['font'];
        }

        // CSS
        if (str_contains($contentType, 'css') || str_ends_with($path, '.css')) {
            return $this->cacheDurations['css'];
        }

        // JavaScript
        if (str_contains($contentType, 'javascript') || str_ends_with($path, '.js')) {
            return $this->cacheDurations['js'];
        }

        return 0;
    }
}
