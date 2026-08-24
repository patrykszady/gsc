<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Local Falcon API client — geo-grid Google Maps rank scans.
 *
 * The one channel with zero instrumentation before this: the local pack sits
 * on 31 of 36 tracked commercial SERPs (measured 2026-08-23), organic sits
 * beneath it at #8–#24, and nothing recorded where the BUSINESS ranks inside
 * the pack across the service area. Local Falcon's grid scans answer exactly
 * that, per keyword, per map point.
 *
 * Defensive by design: the v1 API's response shapes vary by plan and
 * endpoint version, so parsing never assumes a field exists and the full
 * payload is archived in local_falcon_scans.raw.
 */
class LocalFalconService
{
    private const BASE = 'https://api.localfalcon.com/v1';

    protected ?string $lastError = null;

    public function isConfigured(): bool
    {
        return (string) config('services.localfalcon.key') !== '';
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /** @return array<int,array<string,mixed>>|null recent scan reports, newest first */
    public function recentScans(int $limit = 25): ?array
    {
        $resp = Http::timeout(30)->get(self::BASE . '/reports/', [
            'api_key' => config('services.localfalcon.key'),
        ]);

        if (! $resp->successful()) {
            $this->lastError = 'HTTP ' . $resp->status() . ': ' . mb_substr($resp->body(), 0, 200);

            return null;
        }

        $json = $resp->json();
        $rows = $json['data']['reports'] ?? $json['reports'] ?? $json['data'] ?? null;
        if (! is_array($rows)) {
            $this->lastError = 'Unexpected response shape: ' . mb_substr($resp->body(), 0, 200);

            return null;
        }

        return array_slice(array_values($rows), 0, $limit);
    }
}
