<?php

namespace App\Services;

use App\Models\OAuthToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Search Console API wrapper (free, official).
 *
 * Mirrors the OAuth pattern from GoogleBusinessProfileService but uses a
 * separate refresh token because the required scope differs:
 *   https://www.googleapis.com/auth/webmasters.readonly
 *
 * Setup:
 *   1. Run `php artisan seo:gsc-auth` and visit the URL it prints to grant
 *      access. Paste the resulting code back into the prompt.
 *   2. Or set GOOGLE_SEARCH_CONSOLE_REFRESH_TOKEN directly in .env.
 *
 * Docs:
 *   https://developers.google.com/webmaster-tools/v1/searchanalytics/query
 */
class GoogleSearchConsoleService
{
    protected const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    protected const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected const API_BASE = 'https://searchconsole.googleapis.com/webmasters/v3';
    protected const SCOPES = 'https://www.googleapis.com/auth/webmasters.readonly';
    public const PROVIDER = 'google_search_console';

    protected ?array $lastError = null;

    public function isConfigured(): bool
    {
        $cfg = config('services.google.search_console');

        return (bool) ($cfg['enabled'] ?? false)
            && ! empty($cfg['client_id'])
            && ! empty($cfg['client_secret'])
            && $this->getRefreshToken()
            && ! empty($cfg['site_url']);
    }

    public function getRefreshToken(): ?string
    {
        $dbToken = OAuthToken::forProvider(self::PROVIDER);
        if ($dbToken?->refresh_token) {
            return $dbToken->refresh_token;
        }

        return config('services.google.search_console.refresh_token') ?: null;
    }

    public function getOAuthUrl(string $redirectUri): string
    {
        return self::AUTH_ENDPOINT . '?' . http_build_query([
            'client_id' => config('services.google.search_console.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    /**
     * Exchange an OAuth code for tokens and persist them. Use with the
     * console `seo:gsc-auth` command (uses urn:ietf:wg:oauth:2.0:oob or
     * any redirect you configured in the Google Cloud client).
     *
     * @return array{success: bool, error?: string}
     */
    public function exchangeCodeAndStore(string $code, string $redirectUri): array
    {
        $resp = Http::asForm()->timeout(20)->post(self::TOKEN_ENDPOINT, [
            'client_id' => config('services.google.search_console.client_id'),
            'client_secret' => config('services.google.search_console.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if (! $resp->successful()) {
            Log::error('GSC: OAuth exchange failed', ['body' => $resp->body()]);
            return ['success' => false, 'error' => $resp->json('error_description') ?? $resp->body()];
        }

        $data = $resp->json();
        if (empty($data['refresh_token'])) {
            return ['success' => false, 'error' => 'No refresh token returned. Re-run with prompt=consent.'];
        }

        OAuthToken::storeTokens(
            provider: self::PROVIDER,
            refreshToken: $data['refresh_token'],
            accessToken: $data['access_token'] ?? null,
            expiresIn: (int) ($data['expires_in'] ?? 3600),
            scopes: explode(' ', self::SCOPES),
        );

        Cache::forget('gsc_access_token');
        return ['success' => true];
    }

    /**
     * Query the Search Analytics API.
     *
     * @param  array<int,string>  $dimensions  e.g. ['date','query','page','country','device']
     * @return array<int,array>|null  rows from the response
     */
    public function querySearchAnalytics(
        string $siteUrl,
        string $startDate,
        string $endDate,
        array $dimensions = ['date', 'query', 'page'],
        int $rowLimit = 25000,
        int $startRow = 0,
    ): ?array {
        $token = $this->getAccessToken();
        if (! $token) {
            return null;
        }

        $url = self::API_BASE . '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';
        $resp = Http::withToken($token)->timeout(60)->post($url, [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => $dimensions,
            'rowLimit' => $rowLimit,
            'startRow' => $startRow,
            'dataState' => 'final',
        ]);

        if (! $resp->successful()) {
            $this->lastError = ['status' => $resp->status(), 'body' => $resp->body()];
            Log::warning('GSC: searchAnalytics query failed', [
                'site' => $siteUrl,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);
            return null;
        }

        return $resp->json('rows', []);
    }

    public function listSites(): ?array
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return null;
        }
        $resp = Http::withToken($token)->timeout(20)->get(self::API_BASE . '/sites');
        return $resp->successful() ? $resp->json('siteEntry', []) : null;
    }

    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    protected function getAccessToken(): ?string
    {
        $cacheKey = 'gsc_access_token';
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $dbToken = OAuthToken::forProvider(self::PROVIDER);
        if ($dbToken?->hasValidAccessToken()) {
            Cache::put($cacheKey, $dbToken->access_token, $dbToken->access_token_expires_at);
            return $dbToken->access_token;
        }

        $refresh = $this->getRefreshToken();
        if (! $refresh) {
            $this->lastError = ['message' => 'No refresh token. Run php artisan seo:gsc-auth'];
            return null;
        }

        $resp = Http::asForm()->timeout(20)->post(self::TOKEN_ENDPOINT, [
            'client_id' => config('services.google.search_console.client_id'),
            'client_secret' => config('services.google.search_console.client_secret'),
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);

        if (! $resp->successful()) {
            $this->lastError = ['status' => $resp->status(), 'body' => $resp->body()];
            Log::warning('GSC: token refresh failed', ['body' => $resp->body()]);
            return null;
        }

        $data = $resp->json();
        $token = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3000);

        if ($token) {
            Cache::put($cacheKey, $token, now()->addSeconds(max($expiresIn - 120, 300)));
            if ($dbToken) {
                $dbToken->update([
                    'access_token' => $token,
                    'access_token_expires_at' => now()->addSeconds($expiresIn - 120),
                ]);
            }
        }

        return $token;
    }
}
