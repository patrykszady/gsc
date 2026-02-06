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

    /**
     * Upload a project image to Google Business Profile.
     */
    public function uploadProjectImage(ProjectImage $image): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $imageUrl = $this->getPublicImageUrl($image);
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
            'locationAssociation' => [
                'category' => $this->mapCategory($image),
            ],
            'sourceUrl' => $imageUrl,
            'description' => $this->buildDescription($image),
        ];

        $url = $this->mediaBaseUrl() . '/media';

        $response = Http::withToken($accessToken)
            ->timeout(60)
            ->post($url, $payload);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Upload failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::warning('GBP: Failed to upload media', [
                'image_id' => $image->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();
        $this->lastError = null;

        Log::info('GBP: Uploaded image', [
            'image_id' => $image->id,
            'media_name' => $data['name'] ?? null,
            'category' => $payload['locationAssociation']['category'],
        ]);

        return $data['name'] ?? null;
    }

    /**
     * Delete a media item from Google Business Profile.
     */
    public function deleteMedia(string $mediaName): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return false;
        }

        $url = self::MEDIA_API_BASE . "/{$mediaName}";

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->delete($url);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Delete failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::warning('GBP: Failed to delete media', [
                'media_name' => $mediaName,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        $this->lastError = null;

        Log::info('GBP: Deleted media', ['media_name' => $mediaName]);

        return true;
    }

    /**
     * List all media items on the Google Business Profile location.
     */
    public function listMedia(?string $pageToken = null, int $pageSize = 100): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }

        $url = $this->mediaBaseUrl() . '/media';
        $params = ['pageSize' => $pageSize];
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->get($url, $params);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'List media failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::warning('GBP: Failed to list media', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $this->lastError = null;

        return $response->json();
    }

    /**
     * List ALL media items (auto-paginating).
     */
    public function listAllMedia(): array
    {
        $all = [];
        $pageToken = null;

        do {
            $result = $this->listMedia($pageToken);
            if ($result === null) {
                break;
            }

            $items = $result['mediaItems'] ?? [];
            $all = array_merge($all, $items);
            $pageToken = $result['nextPageToken'] ?? null;
        } while ($pageToken);

        return $all;
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

    /**
     * Get upload statistics for display.
     */
    public function getStats(): array
    {
        $total = ProjectImage::count();
        $uploaded = ProjectImage::whereNotNull('google_places_uploaded_at')->count();
        $pending = $total - $uploaded;

        return [
            'total' => $total,
            'uploaded' => $uploaded,
            'pending' => $pending,
        ];
    }

    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    /**
     * Get a publicly accessible URL for the image.
     * GBP requires the URL to be reachable from the internet.
     */
    protected function getPublicImageUrl(ProjectImage $image): ?string
    {
        // Build URL using the production domain
        $productionUrl = config('services.google.business_profile.production_url')
            ?: config('app.url');

        $relativeUrl = $image->getAnyUrl('large');
        if (! $relativeUrl) {
            return null;
        }

        // If already absolute with the right domain, return as-is
        if (str_starts_with($relativeUrl, 'https://')) {
            return $relativeUrl;
        }

        // If it's a local Storage URL, rewrite to the production domain
        $storagePath = str_replace('/storage/', '', parse_url($relativeUrl, PHP_URL_PATH) ?: '');

        return rtrim($productionUrl, '/') . '/storage/' . ltrim($storagePath, '/');
    }

    /**
     * Map project type to a GBP media category.
     *
     * Categories: COVER, PROFILE, LOGO, EXTERIOR, INTERIOR, PRODUCT,
     *             AT_WORK, FOOD_AND_DRINK, MENU, COMMON_AREA, ROOMS, TEAMS, ADDITIONAL
     */
    protected function mapCategory(ProjectImage $image): string
    {
        $project = $image->project;

        if (! $project) {
            return 'ADDITIONAL';
        }

        // Some locations do not allow certain categories. Default to ADDITIONAL
        // to avoid INVALID_ARGUMENT errors like "Photo tag 'interior' does not apply".
        return 'ADDITIONAL';
    }

    protected function buildDescription(ProjectImage $image): string
    {
        $text = $image->caption
            ?: $image->getRawOriginal('seo_alt_text')
            ?: $image->alt_text
            ?: 'GS Construction remodeling project photo.';

        return Str::limit(trim($text), 250, '');
    }

    /**
     * Build the media API base URL for the configured location.
     */
    protected function mediaBaseUrl(): string
    {
        $accountId = config('services.google.business_profile.account_id');
        $locationId = config('services.google.business_profile.location_id');

        return self::MEDIA_API_BASE . "/accounts/{$accountId}/locations/{$locationId}";
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
