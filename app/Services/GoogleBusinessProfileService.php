<?php

namespace App\Services;

use App\Models\OAuthToken;
use App\Models\ProjectImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class GoogleBusinessProfileService
{
    protected const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    protected const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/userinfo';
    protected const MEDIA_API_BASE = 'https://mybusiness.googleapis.com/v4';
    protected const ACCOUNT_API_BASE = 'https://mybusinessaccountmanagement.googleapis.com/v1';
    protected const INFO_API_BASE = 'https://mybusinessbusinessinformation.googleapis.com/v1';
    protected const SCOPES = 'https://www.googleapis.com/auth/business.manage openid email';
    public const PROVIDER = 'google_business_profile';

    protected ?array $lastError = null;

    public function isConfigured(): bool
    {
        $config = config('services.google.business_profile');

        return (bool) ($config['enabled'] ?? false)
            && ! empty($config['client_id'])
            && ! empty($config['client_secret'])
            && $this->hasRefreshToken()
            && ! empty($config['account_id'])
            && ! empty($config['location_id']);
    }

    public function hasOAuthCredentials(): bool
    {
        $config = config('services.google.business_profile');

        return ! empty($config['client_id'])
            && ! empty($config['client_secret'])
            && $this->hasRefreshToken();
    }

    /**
     * Check if a refresh token exists in DB or .env.
     */
    public function hasRefreshToken(): bool
    {
        return (bool) $this->getRefreshToken();
    }

    /**
     * Get the refresh token from DB first, then .env fallback.
     */
    public function getRefreshToken(): ?string
    {
        $dbToken = OAuthToken::forProvider(self::PROVIDER);
        if ($dbToken?->refresh_token) {
            return $dbToken->refresh_token;
        }

        $envToken = config('services.google.business_profile.refresh_token');

        return $envToken ?: null;
    }

    /**
     * Get the DB token record (if any).
     */
    public function getStoredToken(): ?OAuthToken
    {
        return OAuthToken::forProvider(self::PROVIDER);
    }

    /*
    |--------------------------------------------------------------------------
    |  Web-based OAuth flow
    |--------------------------------------------------------------------------
    */

    /**
     * Generate the Google OAuth consent URL for the admin to authorise.
     */
    public function getOAuthUrl(string $redirectUri): string
    {
        $params = http_build_query([
            'client_id' => config('services.google.business_profile.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent', // force new refresh token every time
        ]);

        return self::AUTH_ENDPOINT . '?' . $params;
    }

    /**
     * Exchange an OAuth authorisation code for tokens and persist them.
     *
     * @return array{success: bool, error?: string}
     */
    public function exchangeCodeAndStore(string $code, string $redirectUri): array
    {
        $response = Http::asForm()->timeout(20)->post(self::TOKEN_ENDPOINT, [
            'client_id' => config('services.google.business_profile.client_id'),
            'client_secret' => config('services.google.business_profile.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if (! $response->successful()) {
            $error = $response->json();
            $msg = $error['error_description'] ?? $response->body();
            Log::error('GBP: OAuth code exchange failed', ['body' => $response->body()]);

            return ['success' => false, 'error' => $msg];
        }

        $data = $response->json();
        $refreshToken = $data['refresh_token'] ?? null;
        $accessToken = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3600);

        if (! $refreshToken) {
            return ['success' => false, 'error' => 'No refresh token returned. Try again with prompt=consent.'];
        }

        // Fetch the email of the authorising user
        $email = null;
        if ($accessToken) {
            try {
                $userInfo = Http::withToken($accessToken)->get(self::USERINFO_ENDPOINT)->json();
                $email = $userInfo['email'] ?? null;
            } catch (\Exception) {
                // non-critical
            }
        }

        OAuthToken::storeTokens(
            provider: self::PROVIDER,
            refreshToken: $refreshToken,
            accessToken: $accessToken,
            expiresIn: $expiresIn,
            email: $email,
            scopes: explode(' ', self::SCOPES),
        );

        // Clear any cooldown from previous invalid_grant errors
        $this->clearInvalidGrantCooldown();

        Log::info('GBP: OAuth tokens stored via web flow', ['email' => $email]);

        return ['success' => true];
    }

    /**
     * Disconnect: remove stored tokens.
     */
    public function disconnect(): void
    {
        OAuthToken::where('provider', self::PROVIDER)->delete();
        Cache::forget('google_business_profile_access_token');
        $this->clearInvalidGrantCooldown();
        Log::info('GBP: Disconnected (tokens removed)');
    }

    /**
     * Clear invalid_grant cooldown caches.
     */
    protected function clearInvalidGrantCooldown(): void
    {
        $refreshToken = $this->getRefreshToken();
        if ($refreshToken) {
            $hash = sha1($refreshToken);
            Cache::forget("google_business_profile_invalid_grant:{$hash}");
            Cache::forget("google_business_profile_invalid_grant_logged:{$hash}");
        }
        Cache::forget('google_business_profile_access_token');
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
     * Fetch a media item from GBP.
     */
    public function getMediaItem(string $mediaName): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }

        $url = self::MEDIA_API_BASE . "/{$mediaName}";

        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->get($url);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Get media failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::warning('GBP: Failed to fetch media item', [
                'media_name' => $mediaName,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $this->lastError = null;

        return $response->json();
    }

    /**
     * Get a public Google URL for a GBP media item.
     */
    public function getMediaUrl(string $mediaName): ?string
    {
        $item = $this->getMediaItem($mediaName);
        if (! $item) {
            return null;
        }

        return $item['googleUrl']
            ?? $item['thumbnailUrl']
            ?? null;
    }

    /**
     * Get a cached public Google URL for a GBP media item.
     */
    public function getMediaUrlCached(string $mediaName, int $ttlSeconds = 604800): ?string
    {
        if (! $mediaName) {
            return null;
        }

        $cacheKey = 'gbp_media_url_' . $mediaName;

        return Cache::remember($cacheKey, $ttlSeconds, function () use ($mediaName) {
            return $this->getMediaUrl($mediaName);
        });
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

    /* ------------------------------------------------------------------ */
    /*  Local Posts ("Updates" on the GBP listing)                        */
    /* ------------------------------------------------------------------ */

    /**
     * Create a Local Post on the Google Business Profile listing.
     *
     * These appear as "Updates" on the listing and in Google Maps.
     * Includes a photo, summary text, and a CTA button linking to the site.
     *
     * @return array{name: string, searchUrl: string|null}|null
     */
    public function createLocalPost(string $imageUrl, string $summary, string $ctaUrl, string $ctaType = 'LEARN_MORE'): ?array
    {
        if (! $this->isConfigured()) {
            $this->lastError = ['message' => 'GBP not configured'];
            return null;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }

        $payload = [
            'languageCode' => 'en',
            'summary' => mb_substr($summary, 0, 1500), // GBP limit
            'callToAction' => [
                'actionType' => $ctaType, // BOOK, ORDER, SHOP, LEARN_MORE, SIGN_UP, CALL
                'url' => $ctaUrl,
            ],
            'media' => [
                [
                    'mediaFormat' => 'PHOTO',
                    'sourceUrl' => $imageUrl,
                ],
            ],
            'topicType' => 'STANDARD',
        ];

        $url = $this->locationBaseUrl() . '/localPosts';

        $response = Http::withToken($accessToken)
            ->timeout(60)
            ->post($url, $payload);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'GBP local post failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::warning('GBP: Failed to create local post', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $data = $response->json();
        $this->lastError = null;

        Log::info('GBP: Created local post', [
            'name' => $data['name'] ?? null,
            'search_url' => $data['searchUrl'] ?? null,
        ]);

        return [
            'name' => $data['name'] ?? '',
            'searchUrl' => $data['searchUrl'] ?? null,
        ];
    }

    /**
     * List local posts on the GBP listing.
     */
    public function listLocalPosts(int $pageSize = 10): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }

        $url = $this->locationBaseUrl() . '/localPosts';

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->get($url, ['pageSize' => $pageSize]);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'List local posts failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            return null;
        }

        return $response->json('localPosts', []);
    }

    /* ------------------------------------------------------------------ */
    /*  Reviews                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Fetch reviews for the configured GBP location.
     *
     * Uses the My Business Account Management API v4 endpoint.
     *
     * @return array{reviews: array, totalReviewCount: int, averageRating: float, nextPageToken: string|null}|null
     */
    public function fetchReviews(?string $pageToken = null, int $pageSize = 50): ?array
    {
        if (! $this->isConfigured()) {
            $this->lastError = ['message' => 'GBP not configured'];
            return null;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }

        $url = $this->locationBaseUrl() . '/reviews';
        $params = ['pageSize' => $pageSize];
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->get($url, $params);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Fetch reviews failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::warning('GBP: Failed to fetch reviews', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $this->lastError = null;
        $data = $response->json();

        return [
            'reviews' => $data['reviews'] ?? [],
            'totalReviewCount' => (int) ($data['totalReviewCount'] ?? 0),
            'averageRating' => (float) ($data['averageRating'] ?? 0),
            'nextPageToken' => $data['nextPageToken'] ?? null,
        ];
    }

    /**
     * Fetch ALL reviews (auto-paginating).
     */
    public function fetchAllReviews(): array
    {
        $all = [];
        $pageToken = null;

        do {
            $result = $this->fetchReviews($pageToken);
            if ($result === null) {
                break;
            }

            $all = array_merge($all, $result['reviews']);
            $pageToken = $result['nextPageToken'];
        } while ($pageToken);

        return $all;
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

        // GBP expects JPG; generate a full-size JPG copy for uploads.
        $relativeUrl = $this->getGbpJpegUrl($image)
            ?? $image->url;
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
     * Create or reuse a full-size JPG for GBP uploads.
     */
    protected function getGbpJpegUrl(ProjectImage $image): ?string
    {
        $disk = $image->disk ?: 'public';
        $path = $image->path;

        if (! $path || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $dir = pathinfo($path, PATHINFO_DIRNAME);
        $nameWithoutExt = pathinfo($path, PATHINFO_FILENAME);
        $jpgPath = trim($dir, '/') . '/' . $nameWithoutExt . '_gbp.jpg';

        if (! Storage::disk($disk)->exists($jpgPath)) {
            try {
                $contents = Storage::disk($disk)->get($path);
                $jpg = Image::read($contents)->toJpeg(90)->toString();
                Storage::disk($disk)->put($jpgPath, $jpg);
            } catch (\Exception $e) {
                Log::warning('GBP: Failed to generate JPG for image', [
                    'image_id' => $image->id,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        return Storage::disk($disk)->url($jpgPath);
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
        return $this->locationBaseUrl();
    }

    /**
     * Build the base URL for the configured location (used by media + local posts).
     */
    protected function locationBaseUrl(): string
    {
        $accountId = config('services.google.business_profile.account_id');
        $locationId = config('services.google.business_profile.location_id');

        return self::MEDIA_API_BASE . "/accounts/{$accountId}/locations/{$locationId}";
    }

    /**
     * Build the Info API URL for the configured location.
     */
    protected function infoLocationUrl(): string
    {
        $locationId = config('services.google.business_profile.location_id');

        return self::INFO_API_BASE . "/locations/{$locationId}";
    }

    /* ------------------------------------------------------------------ */
    /*  Location / Profile                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Fetch the current location details from the Business Information API.
     */
    public function getLocation(string $readMask = 'name,title,categories,serviceArea,websiteUri'): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->get($this->infoLocationUrl(), [
                'readMask' => $readMask,
            ]);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Get location failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::warning('GBP: Failed to get location', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $this->lastError = null;

        return $response->json();
    }

    /**
     * Update the service area on the GBP listing.
     *
     * Google allows up to 20 service areas for service-area businesses.
     *
     * @param  array<string>  $cities  City names (e.g., ['Palatine, IL', 'Arlington Heights, IL'])
     * @param  string|null  $businessType  CUSTOMER_AND_BUSINESS_LOCATION or CUSTOMER_LOCATION_ONLY (null = auto-detect from current profile)
     */
    public function updateServiceArea(array $cities, ?string $businessType = null): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }

        // Fetch current profile — need both businessType and storefrontAddress.
        // The GBP API always requires storefrontAddress in the update mask
        // alongside serviceArea, even for CUSTOMER_LOCATION_ONLY businesses.
        $current = $this->getLocation('serviceArea,storefrontAddress');

        if (! $businessType) {
            $businessType = $current['serviceArea']['businessType'] ?? 'CUSTOMER_LOCATION_ONLY';
        }

        $placeInfos = array_map(fn (string $city) => [
            'placeName' => $city,
        ], $cities);

        $payload = [
            'serviceArea' => [
                'businessType' => $businessType,
                'places' => [
                    'placeInfos' => $placeInfos,
                ],
            ],
        ];

        // Always include storefrontAddress in the patch — API requires it
        // when updating serviceArea regardless of business type.
        $address = $current['storefrontAddress'] ?? null;
        if ($address) {
            $payload['storefrontAddress'] = $address;
        }

        $url = $this->infoLocationUrl() . '?updateMask=serviceArea,storefrontAddress';

        Log::debug('GBP: Service area update request', [
            'url' => $url,
            'business_type' => $businessType,
            'has_storefront_address' => isset($payload['storefrontAddress']),
            'cities_count' => count($cities),
        ]);

        $response = Http::withToken($accessToken)
            ->timeout(60)
            ->patch($url, $payload);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Update service area failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::warning('GBP: Failed to update service area', [
                'status' => $response->status(),
                'body' => $response->body(),
                'cities_count' => count($cities),
                'payload_keys' => array_keys($payload),
            ]);

            return null;
        }

        $this->lastError = null;
        $data = $response->json();

        Log::info('GBP: Updated service area', [
            'cities_count' => count($placeInfos),
            'business_type' => $businessType,
        ]);

        return $data;
    }

    /**
     * Update the GBP listing categories.
     *
     * @param  string  $primaryCategoryId  e.g. 'gcid:remodeler'
     * @param  array<string>  $additionalCategoryIds  e.g. ['gcid:kitchen_remodeler', 'gcid:bathroom_remodeler']
     */
    public function updateCategories(string $primaryCategoryId, array $additionalCategoryIds = []): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }

        $payload = [
            'categories' => [
                'primaryCategory' => [
                    'name' => "categories/{$primaryCategoryId}",
                ],
                'additionalCategories' => array_map(fn (string $id) => [
                    'name' => "categories/{$id}",
                ], $additionalCategoryIds),
            ],
        ];

        $url = $this->infoLocationUrl() . '?updateMask=categories';

        $response = Http::withToken($accessToken)
            ->timeout(60)
            ->patch($url, $payload);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Update categories failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::warning('GBP: Failed to update categories', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $this->lastError = null;
        $data = $response->json();

        Log::info('GBP: Updated categories', [
            'primary' => $primaryCategoryId,
            'additional' => $additionalCategoryIds,
        ]);

        return $data;
    }

    /**
     * Search available GBP categories by keyword.
     */
    public function searchCategories(string $query, string $regionCode = 'US', string $languageCode = 'en'): ?array
    {
        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->get(self::INFO_API_BASE . '/categories', [
                'regionCode' => $regionCode,
                'languageCode' => $languageCode,
                'filter' => "categoryName=\"{$query}\"",
                'pageSize' => 20,
            ]);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Search categories failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];

            return null;
        }

        return $response->json('categories', []);
    }

    protected function getAccessToken(): ?string
    {
        $cacheKey = 'google_business_profile_access_token';
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        // Check DB for a still-valid access token
        $dbToken = OAuthToken::forProvider(self::PROVIDER);
        if ($dbToken?->hasValidAccessToken()) {
            Cache::put($cacheKey, $dbToken->access_token, $dbToken->access_token_expires_at);

            return $dbToken->access_token;
        }

        $refreshToken = $this->getRefreshToken();
        if (! $refreshToken) {
            $this->lastError = [
                'message' => 'No refresh token available (DB or .env)',
                'reauthorization_required' => true,
            ];

            return null;
        }

        $refreshTokenHash = sha1($refreshToken);
        $invalidGrantCooldownKey = "google_business_profile_invalid_grant:{$refreshTokenHash}";

        if (Cache::get($invalidGrantCooldownKey)) {
            $this->lastError = [
                'message' => 'Token refresh blocked: re-authorization required',
                'status' => 400,
                'error' => 'invalid_grant',
                'error_description' => 'Refresh token has expired or been revoked.',
                'reauthorization_required' => true,
            ];

            return null;
        }

        $response = Http::asForm()->timeout(20)->post(self::TOKEN_ENDPOINT, [
            'client_id' => config('services.google.business_profile.client_id'),
            'client_secret' => config('services.google.business_profile.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $errorPayload = $response->json() ?: [];
            $errorCode = $errorPayload['error'] ?? null;
            $errorDescription = $errorPayload['error_description'] ?? null;
            $isInvalidGrant = $response->status() === 400 && $errorCode === 'invalid_grant';

            $this->lastError = [
                'message' => 'Token refresh failed',
                'status' => $response->status(),
                'body' => $response->body(),
                'error' => $errorCode,
                'error_description' => $errorDescription,
                'reauthorization_required' => $isInvalidGrant,
            ];

            if ($isInvalidGrant) {
                Cache::forget($cacheKey);
                Cache::put($invalidGrantCooldownKey, true, now()->addHours(6));

                $invalidGrantLoggedKey = "google_business_profile_invalid_grant_logged:{$refreshTokenHash}";
                if (Cache::add($invalidGrantLoggedKey, true, now()->addHours(6))) {
                    Log::error('GBP: Refresh token invalid_grant (expired/revoked). Re-authenticate via Admin > GBP Settings.', [
                        'status' => $response->status(),
                        'error' => $errorCode,
                        'error_description' => $errorDescription,
                    ]);
                }
            } else {
                Log::warning('GBP: Failed to refresh access token', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return null;
        }

        $data = $response->json();
        $token = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3000);

        if ($token) {
            Cache::forget($invalidGrantCooldownKey);
            Cache::forget("google_business_profile_invalid_grant_logged:{$refreshTokenHash}");
            Cache::put($cacheKey, $token, now()->addSeconds(max($expiresIn - 120, 300)));

            // Persist the new access token to DB so it survives cache clears
            if ($dbToken) {
                $dbToken->update([
                    'access_token' => $token,
                    'access_token_expires_at' => now()->addSeconds($expiresIn - 120),
                ]);
            }

            // If Google returned a rotated refresh token, persist it
            if (! empty($data['refresh_token']) && $data['refresh_token'] !== $refreshToken) {
                $stored = $dbToken ?? OAuthToken::storeTokens(
                    provider: self::PROVIDER,
                    refreshToken: $data['refresh_token'],
                    accessToken: $token,
                    expiresIn: $expiresIn,
                );
                if ($dbToken) {
                    $dbToken->update(['refresh_token' => $data['refresh_token']]);
                }
                Log::info('GBP: Refresh token rotated and persisted to DB.');
            }
        }

        return $token;
    }
}
