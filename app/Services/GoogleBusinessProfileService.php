<?php

namespace App\Services;

use App\Models\AreaServed;
use App\Models\OAuthToken;
use App\Models\ProjectImage;
use App\Support\GoogleBusinessListing;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SsSystems\Platform\Media\GooglePhotoCopy;

class GoogleBusinessProfileService
{
    protected const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    protected const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    protected const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/userinfo';

    protected const MEDIA_API_BASE = 'https://mybusiness.googleapis.com/v4';

    protected const ACCOUNT_API_BASE = 'https://mybusinessaccountmanagement.googleapis.com/v1';

    protected const INFO_API_BASE = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    protected const SCOPES = 'https://www.googleapis.com/auth/business.manage openid email';

    /** The scope every Business Profile call needs; identity scopes alone are not enough. */
    public const BUSINESS_SCOPE = 'https://www.googleapis.com/auth/business.manage';

    public const PROVIDER = 'google_business_profile';

    protected ?array $lastError = null;

    /**
     * Ready to post: connected is enough. There used to be a separate
     * "Turn publishing on" switch on the Platforms card as well, and a
     * listing could be connected with it off — the Social Media screen then
     * said "turn publishing on" beside a Platforms card that said Connected.
     * It was a third lock on something already locked twice by the owner:
     * nothing posts unless someone clicks Post Now or turns on that
     * platform's "Post automatically" (off by default). Retired 2026-09-23;
     * the stored gbp.enabled flag is no longer read.
     */
    public function isConfigured(): bool
    {
        return $this->isConnected();
    }

    /**
     * Signed in with a listing chosen — which is also what isConfigured()
     * means now that the separate publishing switch is gone.
     */
    public function isConnected(): bool
    {
        $config = config('services.google.business_profile');

        return ! empty($config['client_id'])
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
    public function getOAuthUrl(string $redirectUri, ?string $state = null): string
    {
        $params = http_build_query(array_filter([
            'client_id' => config('services.google.business_profile.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent', // force new refresh token every time
            'state' => $state,
        ]));

        return self::AUTH_ENDPOINT.'?'.$params;
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
            Log::channel('gbp')->error('GBP: OAuth code exchange failed', ['body' => $response->body()]);

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
            // What Google GRANTED, not what we asked for. The consent screen
            // lets a user untick Business Profile and approve only the identity
            // scopes; storing self::SCOPES recorded a business.manage grant that
            // did not exist, so the admin showed a healthy "Authorisation on
            // file" while every Business Profile call came back 403.
            scopes: array_values(array_filter(explode(' ', (string) ($data['scope'] ?? self::SCOPES)))),
        );

        // Clear any cooldown from previous invalid_grant errors
        $this->clearInvalidGrantCooldown();

        Log::channel('gbp')->info('GBP: OAuth tokens stored via web flow', ['email' => $email]);

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
        Log::channel('gbp')->info('GBP: Disconnected (tokens removed)');
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
    /**
     * Upload an image to GBP. Returns the created MediaItem's
     * `['name' => ..., 'url' => ...]` so callers can persist both the
     * resource name and the public lh3.googleusercontent.com URL.
     *
     * @return array{name: string, url: ?string}|null
     */
    public function uploadProjectImage(ProjectImage $image): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $imageUrl = $this->getPublicImageUrl($image);
        if (! $imageUrl) {
            Log::channel('gbp')->warning('GBP: Image URL not available', ['image_id' => $image->id]);

            return null;
        }

        $accountId = (string) config('services.google.business_profile.account_id');
        $locationId = (string) config('services.google.business_profile.location_id');

        $result = $this->uploadMediaFor($accountId, $locationId, $imageUrl, $this->mapCategory($image), $this->buildDescription($image));

        if ($result) {
            Log::channel('gbp')->info('GBP: Uploaded image', [
                'image_id' => $image->id,
                'media_name' => $result['name'],
                'has_url' => $result['url'] !== null,
            ]);
        }

        return $result;
    }

    /**
     * The same Google call uploadProjectImage() makes, generalized to any
     * account/location this grant can reach instead of the site's own
     * locationBaseUrl() — the central admin's project-photo pass-through
     * (2026-09-21): one Google grant, many listings, account and location
     * passed in exactly as fetchReviewsFor()'s are.
     *
     * @return array{name: string, url: ?string}|null
     */
    public function uploadMediaFor(string $accountId, string $locationId, string $sourceUrl, string $category, ?string $description): ?array
    {
        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }

        $payload = array_filter([
            'mediaFormat' => 'PHOTO',
            'locationAssociation' => [
                'category' => $category,
            ],
            'sourceUrl' => $sourceUrl,
            'description' => $description,
        ], fn ($value) => $value !== null);

        $url = self::MEDIA_API_BASE."/accounts/{$accountId}/locations/{$locationId}/media";

        $response = Http::withToken($accessToken)
            ->timeout(60)
            ->post($url, $payload);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Upload failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::channel('gbp')->warning('GBP: Failed to upload media', [
                'account_id' => $accountId,
                'location_id' => $locationId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();
        $this->lastError = null;

        $mediaName = $data['name'] ?? null;
        if (! $mediaName) {
            return null;
        }

        // Sized here so every caller that persists this URL stores the
        // full-resolution form. Google hands back a bare URL, which renders as
        // a 512px thumbnail — see sizedMediaUrl().
        $googleUrl = self::sizedMediaUrl($data['googleUrl'] ?? $data['thumbnailUrl'] ?? null);

        Log::channel('gbp')->info('GBP: Uploaded media', [
            'account_id' => $accountId,
            'location_id' => $locationId,
            'media_name' => $mediaName,
            'has_url' => $googleUrl !== null,
            'category' => $category,
        ]);

        return [
            'name' => $mediaName,
            'url' => $googleUrl,
        ];
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

        $url = self::MEDIA_API_BASE."/{$mediaName}";

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->delete($url);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Delete failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::channel('gbp')->warning('GBP: Failed to delete media', [
                'media_name' => $mediaName,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        $this->lastError = null;

        Log::channel('gbp')->info('GBP: Deleted media', ['media_name' => $mediaName]);

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

        $url = self::MEDIA_API_BASE."/{$mediaName}";

        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->get($url);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Get media failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::channel('gbp')->warning('GBP: Failed to fetch media item', [
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

        return self::sizedMediaUrl($item['googleUrl'] ?? $item['thumbnailUrl'] ?? null);
    }

    /**
     * Get a cached public Google URL for a GBP media item.
     *
     * Positive results (real URL) are cached for `$ttlSeconds` (default 7 days).
     * Negative results (null — transient API failure, rate limit, or a media
     * item that was deleted on Google's side) are cached for only 5 minutes
     * so a brief upstream blip doesn't hide the "View on Google" link on
     * every project image page for a week.
     */
    /**
     * Append a size token to a googleusercontent.com URL.
     *
     * A bare googleusercontent URL serves a SMALL default rendition — 512px on
     * the long edge, ~53KB — which made our uploads look like low-quality
     * images even though the originals we sent Google are intact. `=s0` asks
     * for that original back (3200x2134, ~1.8MB for a typical project photo).
     *
     * Read-time only: the bare URL stays in the column as the canonical
     * identity Google gave us, and the size stays a presentation concern.
     *
     * @param  string  $size  Google size token — s0 = original, w2400 = 2400px wide.
     */
    public static function sizedMediaUrl(?string $url, string $size = 's0'): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        // Only googleusercontent understands the =size suffix. Other hosts
        // (Yelp, Instagram) share the ImagePlatformUpload table, and appending
        // to their URLs would break them.
        if (! str_contains($url, 'googleusercontent.com')) {
            return $url;
        }

        // Already sized (ours or Google's own) — leave it alone.
        if (preg_match('/=[a-z0-9-]+$/i', $url)) {
            return $url;
        }

        return $url.'='.$size;
    }

    public function getMediaUrlCached(string $mediaName, int $ttlSeconds = 604800): ?string
    {
        if (! $mediaName) {
            return null;
        }

        $cacheKey = 'gbp_media_url_'.$mediaName;
        $negativeTtl = 300; // 5 min

        $cached = Cache::get($cacheKey, '__missing__');
        if ($cached !== '__missing__') {
            // Sized on the way out, not on the way in — entries cached before
            // sizedMediaUrl() existed live for 7 days and would otherwise keep
            // serving the 512px rendition until they expired.
            return self::sizedMediaUrl($cached); // null passes through
        }

        $url = $this->getMediaUrl($mediaName);
        if ($url) {
            Cache::put($cacheKey, $url, $ttlSeconds);
        } else {
            Cache::put($cacheKey, null, $negativeTtl);
            Log::channel('gbp')->info('GBP: cached null media URL (transient failure or deleted item)', [
                'media_name' => $mediaName,
                'negative_ttl_seconds' => $negativeTtl,
            ]);
        }

        return self::sizedMediaUrl($url);
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

        $url = $this->mediaBaseUrl().'/media';
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
            Log::channel('gbp')->warning('GBP: Failed to list media', [
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
     * EVERY media item of a given listing (2026-09-22), for the central
     * admin's per-market photo pass-through: unlike listMedia()/listAllMedia()
     * above, which read THIS site's own configured location, the account and
     * location are passed in — same shape as fetchReviewsFor() and
     * uploadMediaFor() — so the same grant can read any listing it manages.
     * Auto-paginates (Google returns up to 100 items per page) and flattens
     * the result to just what the admin needs to show and match against its
     * own upload ledger. Null + getLastError() on failure, same as every
     * other pass-through here.
     *
     * @return list<array{name: string, source_url: ?string, google_url: ?string, category: ?string, create_time: ?string}>|null
     */
    public function listMediaFor(string $accountId, string $locationId): ?array
    {
        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            $this->lastError ??= ['message' => 'No Google authorization on file'];

            return null;
        }

        $url = self::MEDIA_API_BASE."/accounts/{$accountId}/locations/{$locationId}/media";
        $items = [];
        $pageToken = null;

        do {
            $params = ['pageSize' => 100];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = Http::withToken($accessToken)->timeout(30)->get($url, $params);

            if (! $response->successful()) {
                $this->lastError = [
                    'message' => 'List media failed',
                    'status' => $response->status(),
                    'body' => $response->body(),
                ];
                Log::channel('gbp')->warning('GBP: Failed to list media for listing', [
                    'account_id' => $accountId,
                    'location_id' => $locationId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();

            foreach ($data['mediaItems'] ?? [] as $item) {
                $items[] = [
                    'name' => $item['name'] ?? '',
                    'source_url' => $item['sourceUrl'] ?? null,
                    'google_url' => $item['googleUrl'] ?? null,
                    'category' => $item['locationAssociation']['category'] ?? null,
                    'create_time' => $item['createTime'] ?? null,
                ];
            }

            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken);

        $this->lastError = null;

        return $items;
    }

    /**
     * One listing's reviews, a page at a time (2026-09-22), for the central
     * admin's per-market review import: unlike fetchReviews(), which reads
     * the site's own single listing, the account and location are passed
     * in, so the same grant can read any listing it manages. The import
     * itself lives in ss.systems — this is a pass-through with this site's
     * grant.
     *
     * @return array{reviews: array, totalReviewCount: int, averageRating: float, nextPageToken: ?string}|null
     */
    public function fetchReviewsFor(string $accountId, string $locationId, ?string $pageToken = null, int $pageSize = 50): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            $this->lastError ??= ['message' => 'No Google authorization on file'];

            return null;
        }

        $params = ['pageSize' => $pageSize];
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->get(self::MEDIA_API_BASE."/accounts/{$accountId}/locations/{$locationId}/reviews", $params);

        if (! $response->successful()) {
            $this->lastError = ['message' => 'Fetch reviews failed', 'status' => $response->status(), 'body' => $response->body()];
            Log::channel('gbp')->warning('GBP: Failed to fetch reviews for listing', ['status' => $response->status(), 'location_id' => $locationId]);

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
            ->get(self::ACCOUNT_API_BASE.'/accounts');

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'List accounts failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::channel('gbp')->warning('GBP: Failed to list accounts', [
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
            ->get(self::INFO_API_BASE."/accounts/{$accountId}/locations", [
                // storefrontAddress: the listings endpoint prints the address
                // (it never arrived before). metadata: the public Maps link and
                // place id the central admin fills each market's Google URL from.
                'readMask' => 'name,title,storeCode,websiteUri,storefrontAddress,metadata',
            ]);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'List locations failed',
                'status' => $response->status(),
                'body' => $response->body(),
                'account_id' => $accountId,
            ];
            Log::channel('gbp')->warning('GBP: Failed to list locations', [
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
    /*  Local Posts ("Updates" on the GBP listing) */
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

        $url = $this->locationBaseUrl().'/localPosts';

        $response = Http::withToken($accessToken)
            ->timeout(60)
            ->post($url, $payload);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'GBP local post failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::channel('gbp')->warning('GBP: Failed to create local post', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();
        $this->lastError = null;

        Log::channel('gbp')->info('GBP: Created local post', [
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

        $url = $this->locationBaseUrl().'/localPosts';

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

    /**
     * List ALL local posts on the GBP listing (auto-paginating).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listAllLocalPosts(int $pageSize = 100): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return [];
        }

        $url = $this->locationBaseUrl().'/localPosts';
        $all = [];
        $pageToken = null;

        do {
            $params = ['pageSize' => $pageSize];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = Http::withToken($accessToken)->timeout(30)->get($url, $params);
            if (! $response->successful()) {
                $this->lastError = [
                    'message' => 'List local posts failed',
                    'status' => $response->status(),
                    'body' => $response->body(),
                ];
                break;
            }

            $all = array_merge($all, $response->json('localPosts', []));
            $pageToken = $response->json('nextPageToken');
        } while ($pageToken);

        return $all;
    }

    /**
     * Delete a single local post ("update") from the GBP listing.
     *
     * @param  string  $postName  Full resource name: accounts/{a}/locations/{l}/localPosts/{p}
     */
    public function deleteLocalPost(string $postName): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return false;
        }

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->delete(self::MEDIA_API_BASE."/{$postName}");

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Delete local post failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::channel('gbp')->warning('GBP: Failed to delete local post', [
                'post_name' => $postName,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        $this->lastError = null;
        Log::channel('gbp')->info('GBP: Deleted local post', ['post_name' => $postName]);

        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Reviews */
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

        $url = $this->locationBaseUrl().'/reviews';
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
            Log::channel('gbp')->warning('GBP: Failed to fetch reviews', [
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
        $uploaded = ProjectImage::uploadedTo('google_places')->count();
        $pending = $total - $uploaded;

        return [
            'total' => $total,
            'uploaded' => $uploaded,
            'pending' => $pending,
        ];
    }

    /**
     * Fetch reviews from the Google Places API (New) which includes googleMapsUri per review.
     * Returns up to 5 "most relevant" reviews — an API limitation.
     *
     * @return array<array{authorName: string, rating: int, text: string, publishTime: string, googleMapsUri: string}>|null
     */
    public function fetchPlaceReviews(): ?array
    {
        $placeId = GoogleBusinessListing::placeId();
        $apiKey = config('services.google.places_api_key');

        if (! $placeId || ! $apiKey) {
            $this->lastError = ['message' => 'GOOGLE_BUSINESS_PROFILE_PLACE_ID or GOOGLE_PLACES_API_KEY not set'];

            return null;
        }

        $response = Http::timeout(30)
            ->withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => 'reviews.rating,reviews.text,reviews.originalText,reviews.authorAttribution,reviews.publishTime,reviews.googleMapsUri',
            ])
            ->get("https://places.googleapis.com/v1/places/{$placeId}");

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Places API request failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::channel('gbp')->warning('GBP: Failed to fetch place reviews', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $this->lastError = null;
        $data = $response->json();
        $reviews = [];

        foreach ($data['reviews'] ?? [] as $review) {
            $reviews[] = [
                'authorName' => $review['authorAttribution']['displayName'] ?? '',
                'rating' => (int) ($review['rating'] ?? 0),
                'text' => $review['text']['text'] ?? $review['originalText']['text'] ?? '',
                'publishTime' => $review['publishTime'] ?? '',
                'googleMapsUri' => $review['googleMapsUri'] ?? '',
            ];
        }

        return $reviews;
    }

    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    /**
     * Get a publicly accessible URL for the image.
     * GBP requires the URL to be reachable from the internet.
     *
     * Public (2026-09-21) so the admin API's media pass-through can resolve
     * the same source URL uploadProjectImage() sends Google, for a listing
     * that need not be this site's own — see uploadMediaFor().
     *
     * $latitude/$longitude/$takenAt override the image's own defaults (the
     * market's coordinates from resolveImageCoordinates(), the project's
     * completed_at) — the pass-through's optional captured_at/latitude/
     * longitude params (2026-09-22). Pass all three null (the default) to
     * use the image's own defaults, exactly as uploadProjectImage() does.
     */
    public function getPublicImageUrl(ProjectImage $image, ?float $latitude = null, ?float $longitude = null, ?DateTimeInterface $takenAt = null): ?string
    {
        // Build URL using the production domain
        $productionUrl = config('services.google.business_profile.production_url')
            ?: config('app.url');

        // GBP expects JPG; generate a full-size JPG copy for uploads.
        $relativeUrl = $this->getGbpJpegUrl($image, $latitude, $longitude, $takenAt)
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

        return rtrim($productionUrl, '/').'/storage/'.ltrim($storagePath, '/');
    }

    /**
     * Create or reuse the dated, geotagged copy of a project photo for
     * Google (0.3.1, 2026-09-22): scaled + JPEG-encoded + EXIF-stamped by
     * the shared SsSystems\Platform\Media\GooglePhotoCopy kit, named
     * `{name}-gbp-{fingerprint}.jpg` so the same source, date and place
     * always resolve to the same file — reused when it already exists,
     * regenerated (and its older `-gbp-*.jpg`/legacy `_gbp.jpg` siblings
     * removed) when one of those inputs changes.
     *
     * $latitude/$longitude override resolveImageCoordinates() (given as a
     * pair — a caller supplying one must supply both); $takenAt overrides
     * the project's completed_at. All null uses the image's own defaults.
     */
    protected function getGbpJpegUrl(ProjectImage $image, ?float $latitude = null, ?float $longitude = null, ?DateTimeInterface $takenAt = null): ?string
    {
        $disk = 'public';
        $path = $image->path;

        if (! $path || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        if ($latitude === null && $longitude === null) {
            $geotagEnabled = (bool) config('services.google.business_profile.geotag_photos', true);
            [$latitude, $longitude] = $geotagEnabled
                ? $this->resolveImageCoordinates($image)
                : [null, null];
        }

        $takenAt ??= $this->resolveImageTakenAt($image);

        $dir = trim(pathinfo($path, PATHINFO_DIRNAME), '/');
        $name = pathinfo($path, PATHINFO_FILENAME);
        $sourceKey = $path.'|'.Storage::disk($disk)->size($path);
        $fingerprint = GooglePhotoCopy::fingerprint($sourceKey, $latitude, $longitude, $takenAt);
        $jpgPath = ($dir !== '' ? $dir.'/' : '').$name.'-gbp-'.$fingerprint.'.jpg';

        if (! Storage::disk($disk)->exists($jpgPath)) {
            try {
                $sourceBytes = Storage::disk($disk)->get($path);
                $jpeg = GooglePhotoCopy::make($sourceBytes, $latitude, $longitude, $takenAt);

                Storage::disk($disk)->put($jpgPath, $jpeg);
                $this->removeStaleGbpCopies($disk, $dir, $name, $jpgPath);
            } catch (\Exception $e) {
                Log::channel('gbp')->warning('GBP: Failed to generate JPG for image', [
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
     * Delete this image's other GBP copies once a new one is made — the old
     * fingerprinted name (`{name}-gbp-{fingerprint}.jpg`) and any legacy
     * `{name}_gbp.jpg` from before fingerprinting existed — so a project
     * photo doesn't accumulate a derivative per date/coordinate change.
     */
    protected function removeStaleGbpCopies(string $disk, string $dir, string $name, string $keepPath): void
    {
        $pattern = '/^'.preg_quote($name, '/').'(-gbp-[0-9a-f]+|_gbp)\.jpg$/i';

        foreach (Storage::disk($disk)->files($dir) as $file) {
            if ($file === $keepPath) {
                continue;
            }

            if (preg_match($pattern, pathinfo($file, PATHINFO_BASENAME))) {
                Storage::disk($disk)->delete($file);
            }
        }
    }

    /**
     * The date Google should show as "Image capture" — the project's
     * completion date, not the day the photo was pushed (confirmed
     * 2026-09-22: Google reads the JPEG's EXIF DateTimeOriginal for this).
     * Null when the project has no completed_at, so the copy carries no
     * capture date rather than a fabricated one.
     */
    protected function resolveImageTakenAt(ProjectImage $image): ?DateTimeInterface
    {
        return $image->project?->completed_at;
    }

    /**
     * Resolve the AreaServed coordinates for the project's location.
     * Returns [null, null] when there is no match — caller should then skip
     * GPS injection rather than fabricate coordinates.
     *
     * @return array{0: ?float, 1: ?float}
     */
    protected function resolveImageCoordinates(ProjectImage $image): array
    {
        $location = $image->project?->location;
        if (! $location) {
            return [null, null];
        }

        $city = $this->normalizeCity($location);
        $slug = Str::slug($city);
        $area = AreaServed::query()
            ->where(function ($q) use ($city, $slug) {
                $q->where('city', $city)
                    ->orWhere('slug', $slug);
            })
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->first();

        if (! $area) {
            return [null, null];
        }

        return [(float) $area->latitude, (float) $area->longitude];
    }

    /**
     * Strip state/country suffix and common typos from a free-form
     * "City, IL" / "City. IL" / "City, Illinois, USA" string.
     */
    public function normalizeCity(string $location): string
    {
        // Handle both "," and "." used as separators (real data has both).
        $parts = preg_split('/[,.]/', $location) ?: [$location];
        $city = trim((string) ($parts[0] ?? ''));

        return $city !== '' ? $city : trim($location);
    }

    /**
     * Map project type to a GBP media category.
     *
     * Categories: COVER, PROFILE, LOGO, EXTERIOR, INTERIOR, PRODUCT,
     *             AT_WORK, FOOD_AND_DRINK, MENU, COMMON_AREA, ROOMS, TEAMS, ADDITIONAL
     *
     * Public (2026-09-21): the admin API's media pass-through defaults its
     * optional `category` param to this, same as uploadProjectImage() does.
     */
    public function mapCategory(ProjectImage $image): string
    {
        $project = $image->project;

        if (! $project) {
            return 'ADDITIONAL';
        }

        // Some locations do not allow certain categories. Default to ADDITIONAL
        // to avoid INVALID_ARGUMENT errors like "Photo tag 'interior' does not apply".
        return 'ADDITIONAL';
    }

    /**
     * Public (2026-09-21): the admin API's media pass-through defaults its
     * optional `description` param to this, same as uploadProjectImage() does.
     */
    public function buildDescription(ProjectImage $image): string
    {
        $text = $image->gbp_caption
            ?: $image->caption
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

        return self::MEDIA_API_BASE."/accounts/{$accountId}/locations/{$locationId}";
    }

    /**
     * Build the Info API URL for the configured location.
     */
    protected function infoLocationUrl(): string
    {
        $locationId = config('services.google.business_profile.location_id');

        return self::INFO_API_BASE."/locations/{$locationId}";
    }

    /* ------------------------------------------------------------------ */
    /*  Location / Profile */
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
            Log::channel('gbp')->warning('GBP: Failed to get location', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $this->lastError = null;

        return $response->json();
    }

    /**
     * Fetch the Place ID for the configured GBP location via the Business Information API.
     * The metadata.placeId field is the official Place ID (ChIJ...) for use with the Places API.
     */
    public function fetchPlaceId(): ?string
    {
        $data = $this->getLocation('metadata');

        return $data['metadata']['placeId'] ?? null;
    }

    /**
     * Update the service area on the GBP listing.
     *
     * Google allows up to 20 service areas for service-area businesses.
     * Each PlaceInfo requires both placeName and placeId (resolved via Geocoding API).
     *
     * @param  array<string>  $cities  City names (e.g., ['Palatine, IL, USA', 'Arlington Heights, IL, USA'])
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

        // Fetch current profile for auto-detection of business type
        $current = $this->getLocation('serviceArea');

        if (! $businessType) {
            $businessType = $current['serviceArea']['businessType'] ?? 'CUSTOMER_LOCATION_ONLY';
        }

        // Resolve each city to a place ID via the Geocoding API.
        // The GBP API requires both placeName and placeId for each PlaceInfo.
        $placeInfos = [];
        $failed = [];

        foreach ($cities as $city) {
            $placeId = $this->resolveGeocodePlaceId($city);
            if ($placeId) {
                $placeInfos[] = [
                    'placeName' => $city,
                    'placeId' => $placeId,
                ];
            } else {
                $failed[] = $city;
            }
        }

        if ($failed) {
            Log::channel('gbp')->warning('GBP: Could not resolve place IDs for some cities', [
                'failed' => $failed,
                'resolved' => count($placeInfos),
            ]);
        }

        if (empty($placeInfos)) {
            $this->lastError = [
                'message' => 'Could not resolve any city to a Google Place ID',
                'status' => 0,
                'body' => json_encode(['failed_cities' => $failed]),
            ];

            return null;
        }

        $payload = [
            'serviceArea' => [
                'businessType' => $businessType,
                'places' => [
                    'placeInfos' => $placeInfos,
                ],
            ],
        ];

        $url = $this->infoLocationUrl().'?updateMask=serviceArea';

        Log::channel('gbp')->debug('GBP: Service area update request', [
            'url' => $url,
            'business_type' => $businessType,
            'cities_count' => count($placeInfos),
            'sample_place' => $placeInfos[0] ?? null,
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
            Log::channel('gbp')->warning('GBP: Failed to update service area', [
                'status' => $response->status(),
                'body' => $response->body(),
                'cities_count' => count($placeInfos),
            ]);

            return null;
        }

        $this->lastError = null;
        $data = $response->json();

        Log::channel('gbp')->info('GBP: Updated service area', [
            'cities_count' => count($placeInfos),
            'business_type' => $businessType,
        ]);

        return $data;
    }

    /**
     * Resolve a city/place name to a Google Place ID using the Geocoding API.
     *
     * Returns a place ID of type "locality" or "administrative_area_level_3"
     * (i.e. a region/city), or null if not found.
     */
    /**
     * Public wrapper so commands can preview place-ID resolution for a service
     * area (town or county) before writing to the live profile.
     */
    public function resolveServiceAreaPlaceId(string $name): ?string
    {
        return $this->resolveGeocodePlaceId($name);
    }

    protected function resolveGeocodePlaceId(string $address): ?string
    {
        $apiKey = config('services.google.places_api_key');
        if (! $apiKey) {
            Log::channel('gbp')->warning('GBP: Google Places API key not configured');

            return null;
        }

        // Primary: Places API (New) Text Search. This is the API actually enabled
        // on our Cloud project (the classic Geocoding API returns REQUEST_DENIED),
        // and it resolves both towns and counties to the same Place IDs GBP wants.
        $placeId = $this->resolvePlaceIdViaTextSearch($address, $apiKey);
        if ($placeId) {
            return $placeId;
        }

        // Fallback: classic Geocoding API (used if it's ever enabled on the key).
        $response = Http::timeout(10)
            ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $address,
                'key' => $apiKey,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $results = $response->json()['results'] ?? [];

        // Prefer a city- or county-level region (administrative_area_level_2 =
        // county, one slot covering all its towns).
        foreach ($results as $result) {
            $types = $result['types'] ?? [];
            if (array_intersect($types, ['locality', 'administrative_area_level_3', 'administrative_area_level_2', 'sublocality', 'postal_town'])) {
                return $result['place_id'] ?? null;
            }
        }

        return $results[0]['place_id'] ?? null;
    }

    /**
     * Resolve a place name (town or county) to a Google Place ID via the
     * Places API (New) Text Search endpoint. Returns the same ChIJ… Place IDs
     * the Business Profile serviceArea API expects.
     */
    protected function resolvePlaceIdViaTextSearch(string $query, string $apiKey): ?string
    {
        $response = Http::withHeaders([
            'X-Goog-Api-Key' => $apiKey,
            'X-Goog-FieldMask' => 'places.id,places.formattedAddress',
        ])->timeout(10)->post('https://places.googleapis.com/v1/places:searchText', [
            'textQuery' => $query,
        ]);

        if (! $response->successful()) {
            Log::channel('gbp')->warning('GBP: Places text search failed', [
                'query' => $query,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return null;
        }

        return $response->json('places.0.id');
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

        $url = $this->infoLocationUrl().'?updateMask=categories';

        $response = Http::withToken($accessToken)
            ->timeout(60)
            ->patch($url, $payload);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Update categories failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::channel('gbp')->warning('GBP: Failed to update categories', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $this->lastError = null;
        $data = $response->json();

        Log::channel('gbp')->info('GBP: Updated categories', [
            'primary' => $primaryCategoryId,
            'additional' => $additionalCategoryIds,
        ]);

        return $data;
    }

    /** The "From the business" description on the listing, or null when unreadable. */
    public function getDescription(): ?string
    {
        $location = $this->getLocation('profile');
        $text = is_array($location) ? ($location['profile']['description'] ?? null) : null;

        return is_string($text) ? $text : null;
    }

    /**
     * Replace the "From the business" description (Google allows 750
     * characters, no URLs). Returns the updated location, or null on failure.
     */
    public function updateDescription(string $description): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }
        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }
        $description = mb_substr(trim($description), 0, 750);
        $response = Http::withToken($accessToken)
            ->timeout(60)
            ->patch($this->infoLocationUrl().'?updateMask=profile.description', ['profile' => ['description' => $description]]);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Update description failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::channel('gbp')->warning('GBP: Failed to update description', ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        }
        $this->lastError = null;
        Log::channel('gbp')->info('GBP: Updated description', ['length' => mb_strlen($description)]);

        return $response->json();
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
            ->get(self::INFO_API_BASE.'/categories', [
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

    /**
     * Reply to (or update the owner reply on) a single Google review.
     *
     * @param  string  $reviewName  Full resource name e.g. accounts/{a}/locations/{l}/reviews/{id}
     */
    public function replyToReview(string $reviewName, string $comment): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }

        $url = self::MEDIA_API_BASE."/{$reviewName}/reply";

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->put($url, ['comment' => $comment]);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Review reply failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::channel('gbp')->warning('GBP: Failed to reply to review', [
                'review' => $reviewName,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $this->lastError = null;
        Log::channel('gbp')->info('GBP: Replied to review', ['review' => $reviewName]);

        return $response->json();
    }

    /**
     * Replace the GBP location's service items (custom services that show up
     * on the listing under "Services"). Pass the full list — Google replaces,
     * not merges.
     *
     * @param  array<int, array{name: string, description?: string, price_cents?: int, currency?: string}>  $items
     */
    public function updateServiceItems(array $items): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }

        $serviceItems = [];
        foreach ($items as $item) {
            $node = [
                'freeFormServiceItem' => [
                    'category' => 'gcid:remodeler',
                    'label' => [
                        'displayName' => $item['name'],
                        'languageCode' => 'en',
                    ],
                ],
            ];
            if (! empty($item['description'])) {
                $node['freeFormServiceItem']['label']['description'] = $item['description'];
            }
            if (isset($item['price_cents'])) {
                $node['price'] = [
                    'currencyCode' => $item['currency'] ?? 'USD',
                    'units' => (string) intdiv((int) $item['price_cents'], 100),
                    'nanos' => (((int) $item['price_cents']) % 100) * 10_000_000,
                ];
            }
            $serviceItems[] = $node;
        }

        $url = $this->infoLocationUrl().'?updateMask=serviceItems';

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->patch($url, ['serviceItems' => $serviceItems]);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Update service items failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::channel('gbp')->warning('GBP: Failed to update service items', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $this->lastError = null;
        Log::channel('gbp')->info('GBP: Updated service items', ['count' => count($serviceItems)]);

        return $response->json();
    }

    /**
     * Public accessor for the cached/refreshed GBP access token. Used by
     * sibling services (e.g. GoogleBusinessProfilePerformanceService) so
     * they can call other endpoints under the same business.manage scope
     * without duplicating OAuth logic.
     */
    public function getAuthorizedToken(): ?string
    {
        return $this->getAccessToken();
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
                    Log::channel('gbp')->error('GBP: Refresh token invalid_grant (expired/revoked). Re-authenticate via Admin > GBP Settings.', [
                        'status' => $response->status(),
                        'error' => $errorCode,
                        'error_description' => $errorDescription,
                    ]);
                }
            } else {
                Log::channel('gbp')->warning('GBP: Failed to refresh access token', [
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

            // Every refresh response states the scopes the grant actually
            // carries. Recording them here repairs a row written before we
            // stored the granted set (older code stored the REQUESTED scopes),
            // so a connection that only ever covered sign-in stops claiming
            // otherwise without anyone having to reconnect to find out.
            if (! empty($data['scope']) && $dbToken) {
                $granted = array_values(array_filter(explode(' ', (string) $data['scope'])));

                if ($granted !== [] && $granted !== (array) $dbToken->scopes) {
                    $dbToken->forceFill(['scopes' => $granted])->save();
                }
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
                Log::channel('gbp')->info('GBP: Refresh token rotated and persisted to DB.');
            }
        }

        return $token;
    }

    /** The scopes recorded against the stored authorisation. */
    public function grantedScopes(): array
    {
        return (array) (OAuthToken::forProvider(self::PROVIDER)?->scopes ?? []);
    }

    /**
     * Whether the stored authorisation actually carries business.manage.
     * Without it the connection signs the user in and nothing else: every
     * listing, photo and post call returns 403.
     */
    public function hasBusinessScope(): bool
    {
        return in_array(self::BUSINESS_SCOPE, $this->grantedScopes(), true);
    }
}
