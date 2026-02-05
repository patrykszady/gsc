<?php

namespace App\Services;

use App\Models\ProjectImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleBusinessProfileService
{
    protected const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    protected const MEDIA_API_BASE = 'https://mybusiness.googleapis.com/v4';
    protected const ACCOUNT_API_BASE = 'https://mybusinessaccountmanagement.googleapis.com/v1';
    protected const INFO_API_BASE = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    protected ?array $lastError = null;

    public function isConfigured(): bool
    {
        $config = config('services.google.business_profile');

        return (bool) ($config['enabled'] ?? false)
            && ! empty($config['client_id'])
            && ! empty($config['client_secret'])
            && ! empty($config['refresh_token'])
            && ! empty($config['account_id'])
            && ! empty($config['location_id']);
    }

    public function hasOAuthCredentials(): bool
    {
        $config = config('services.google.business_profile');

        return ! empty($config['client_id'])
            && ! empty($config['client_secret'])
            && ! empty($config['refresh_token']);
    }

    public function uploadProjectImage(ProjectImage $image): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $imageUrl = $image->getAnyUrl('large');
        if (! $imageUrl) {
            Log::warning('GBP: Image URL not available', ['image_id' => $image->id]);
            return null;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }

        $payload = [
            'mediaFormat' => 'PHOTO',
            'sourceUrl' => $imageUrl,
            'description' => $this->buildDescription($image),
        ];

        $accountId = config('services.google.business_profile.account_id');
        $locationId = config('services.google.business_profile.location_id');
        $url = self::MEDIA_API_BASE . "/accounts/{$accountId}/locations/{$locationId}/media";

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('GBP: Failed to upload media', [
                'image_id' => $image->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();

        return $data['name'] ?? null;
    }

    /**
     * List available Google Business Profile accounts.
     */
    public function listAccounts(): array
    {
        if (! $this->hasOAuthCredentials()) {
            $this->lastError ??= ['message' => 'Missing OAuth credentials'];
            return [];
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            $this->lastError ??= ['message' => 'Failed to obtain access token'];
            return [];
        }

        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->get(self::ACCOUNT_API_BASE . '/accounts');

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'List accounts failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::warning('GBP: Failed to list accounts', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $data = $response->json();

        $this->lastError = null;

        return $data['accounts'] ?? [];
    }

    /**
     * List locations for a given account ID.
     */
    public function listLocations(string $accountId): array
    {
        if (! $this->hasOAuthCredentials()) {
            $this->lastError ??= ['message' => 'Missing OAuth credentials'];
            return [];
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            $this->lastError ??= ['message' => 'Failed to obtain access token'];
            return [];
        }

        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->get(self::INFO_API_BASE . "/accounts/{$accountId}/locations", [
                'readMask' => 'name,title,storeCode,websiteUri',
            ]);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'List locations failed',
                'status' => $response->status(),
                'body' => $response->body(),
                'account_id' => $accountId,
            ];
            Log::warning('GBP: Failed to list locations', [
                'status' => $response->status(),
                'body' => $response->body(),
                'account_id' => $accountId,
            ]);

            return [];
        }

        $data = $response->json();

        $this->lastError = null;

        return $data['locations'] ?? [];
    }

    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    protected function buildDescription(ProjectImage $image): string
    {
        $text = $image->caption
            ?: $image->seo_alt_text
            ?: $image->alt_text
            ?: 'GS Construction remodeling project photo.';

        return Str::limit(trim($text), 250, '');
    }

    protected function getAccessToken(): ?string
    {
        $cacheKey = 'google_business_profile_access_token';
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $response = Http::asForm()->timeout(20)->post(self::TOKEN_ENDPOINT, [
            'client_id' => config('services.google.business_profile.client_id'),
            'client_secret' => config('services.google.business_profile.client_secret'),
            'refresh_token' => config('services.google.business_profile.refresh_token'),
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Token refresh failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::warning('GBP: Failed to refresh access token', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();
        $token = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3000);

        if ($token) {
            Cache::put($cacheKey, $token, now()->addSeconds(max($expiresIn - 120, 300)));
        }

        return $token;
    }
}
