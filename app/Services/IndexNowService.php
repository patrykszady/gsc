<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IndexNowService
{
    protected ?string $key;
    protected string $host;
    protected string $endpoint;
    protected bool $enabled;

    public function __construct()
    {
        $this->key = config('indexnow.key') ?? '';
        $this->host = parse_url(config('app.url'), PHP_URL_HOST) ?? '';
        $this->endpoint = config('indexnow.endpoint', 'https://api.indexnow.org/indexnow');
        $this->enabled = config('indexnow.enabled', false);
    }

    /**
     * Submit a single URL to IndexNow
     */
    public function submit(string $url): bool
    {
        return $this->submitBatch([$url]);
    }

    /**
     * Submit multiple URLs to IndexNow (max 10,000 per request)
     */
    public function submitBatch(array $urls): bool
    {
        if (! $this->enabled || empty($this->key) || empty($urls)) {
            Log::debug('IndexNow: Skipping submission', [
                'enabled' => $this->enabled,
                'has_key' => ! empty($this->key),
                'url_count' => count($urls),
            ]);

            return false;
        }

        // Ensure all URLs are absolute
        $urls = array_map(fn ($url) => $this->ensureAbsoluteUrl($url), $urls);

        // IndexNow allows max 10,000 URLs per request
        $chunks = array_chunk($urls, 10000);

        foreach ($chunks as $chunk) {
            try {
                $response = Http::timeout(30)
                    ->post($this->endpoint, [
                        'host' => $this->host,
                        'key' => $this->key,
                        'keyLocation' => url("/{$this->key}.txt"),
                        'urlList' => array_values($chunk),
                    ]);

                if ($response->successful() || $response->status() === 202) {
                    Log::info('IndexNow: URLs submitted successfully', [
                        'count' => count($chunk),
                        'status' => $response->status(),
                    ]);
                } else {
                    Log::warning('IndexNow: Failed to submit URLs', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'urls' => $chunk,
                    ]);

                    return false;
                }
            } catch (\Exception $e) {
                Log::error('IndexNow: Exception during submission', [
                    'message' => $e->getMessage(),
                    'urls' => $chunk,
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * Submit a URL for a named route
     */
    public function submitRoute(string $routeName, array $parameters = []): bool
    {
        try {
            $url = route($routeName, $parameters);

            return $this->submit($url);
        } catch (\Exception $e) {
            Log::error('IndexNow: Failed to generate route URL', [
                'route' => $routeName,
                'parameters' => $parameters,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get the verification key
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Check if IndexNow is enabled and configured
     */
    public function isEnabled(): bool
    {
        return $this->enabled && ! empty($this->key);
    }

    /**
     * Ensure a URL is absolute
     */
    protected function ensureAbsoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return url($url);
    }
}
