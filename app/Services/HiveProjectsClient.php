<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HiveProjectsClient
{
    public function __construct(
        protected ?string $baseUrl = null,
        protected ?string $token = null,
        protected ?int $cacheTtl = null,
    ) {
        $this->baseUrl ??= (string) config('services.hive.url');
        $this->token ??= (string) config('services.hive.token');
        $this->cacheTtl ??= (int) config('services.hive.cache_ttl', 21600);
    }

    /**
     * Returns a collection of ['zip' => string, 'count' => int] ordered by count desc.
     * Returns null on failure so the caller can fall back to its own source.
     */
    public function zipCounts(): ?Collection
    {
        if ($this->token === '' || $this->baseUrl === '') {
            return null;
        }

        return Cache::remember(
            'hive:projects:zip-counts',
            $this->cacheTtl,
            fn () => $this->fetchZipCounts(),
        );
    }

    protected function fetchZipCounts(): ?Collection
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->token)
                ->acceptJson()
                ->timeout(8)
                ->connectTimeout(4)
                ->retry(2, 250, throw: false)
                ->get('/api/v1/projects/zip-counts');
        } catch (ConnectionException $e) {
            Log::warning('HiveProjectsClient connection failure', ['error' => $e->getMessage()]);
            return null;
        } catch (Throwable $e) {
            Log::warning('HiveProjectsClient unexpected error', ['error' => $e->getMessage()]);
            return null;
        }

        if (!$response->successful()) {
            Log::warning('HiveProjectsClient non-2xx response', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);
            return null;
        }

        $rows = $response->json('data');
        if (!is_array($rows)) {
            return null;
        }

        return collect($rows)
            ->map(function ($row) {
                $zip = trim((string) ($row['zip'] ?? ''));
                $count = (int) ($row['count'] ?? 0);
                if ($zip === '' || $count <= 0) {
                    return null;
                }
                return ['zip' => $zip, 'count' => $count];
            })
            ->filter()
            ->sortByDesc('count')
            ->values();
    }
}
