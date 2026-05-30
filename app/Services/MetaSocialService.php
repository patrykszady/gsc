<?php

namespace App\Services;

use App\Models\OAuthToken;
use App\Models\ProjectImage;
use App\Models\ShortLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Meta Graph API service for publishing to Instagram and Facebook.
 *
 * Instagram Container Flow:
 *   1. Create a media container (POST /{ig-user-id}/media) with image_url + caption
 *   2. Publish the container  (POST /{ig-user-id}/media_publish)
 *
 * Facebook Page Photo:
 *   1. POST /{page-id}/photos with url + message
 *
 * Both use the same Page Access Token from a Meta Business App.
 */
class MetaSocialService
{
    protected const GRAPH_BASE = 'https://graph.facebook.com/v25.0';
    protected const OAUTH_PROVIDER = 'meta';

    /**
     * Scopes required for publishing to the linked Instagram Business
     * account. We intentionally do NOT request Facebook Page posting
     * scopes (pages_manage_posts, pages_read_engagement) — those require
     * App Review and we only publish to Instagram.
     */
    protected const OAUTH_SCOPES = [
        'pages_show_list',
        'business_management',
        'instagram_basic',
        'instagram_content_publish',
    ];

    protected ?array $lastError = null;

    /* ------------------------------------------------------------------ */
    /*  Credential resolution (DB first, env fallback)                     */
    /* ------------------------------------------------------------------ */

    /**
     * Resolved Meta credentials for posting.
     *
     * @return array{token: ?string, page_id: ?string, ig_id: ?string, page_name: ?string, ig_username: ?string, source: 'oauth'|'env'|null}
     */
    public function getCredentials(): array
    {
        $token = OAuthToken::forProvider(self::OAUTH_PROVIDER);
        if ($token && $token->access_token) {
            $meta = $token->metadata ?? [];
            return [
                'token' => $token->access_token,
                'page_id' => $meta['page_id'] ?? null,
                'ig_id' => $meta['ig_id'] ?? null,
                'page_name' => $meta['page_name'] ?? null,
                'ig_username' => $meta['ig_username'] ?? null,
                'source' => 'oauth',
            ];
        }

        $cfg = config('services.meta');
        $envToken = trim((string) ($cfg['page_access_token'] ?? ''));
        if ($envToken === '') {
            return ['token' => null, 'page_id' => null, 'ig_id' => null, 'page_name' => null, 'ig_username' => null, 'source' => null];
        }

        return [
            'token' => $envToken,
            'page_id' => trim((string) ($cfg['facebook_page_id'] ?? '')) ?: null,
            'ig_id' => trim((string) ($cfg['instagram_account_id'] ?? '')) ?: null,
            'page_name' => null,
            'ig_username' => null,
            'source' => 'env',
        ];
    }

    public function isConnected(): bool
    {
        return $this->getCredentials()['token'] !== null;
    }

    /* ------------------------------------------------------------------ */
    /*  Configuration helpers                                              */
    /* ------------------------------------------------------------------ */

    public function isInstagramConfigured(): bool
    {
        if (! (bool) (config('services.meta.enabled') ?? false)) {
            return false;
        }
        $c = $this->getCredentials();
        return $c['token'] !== null && ! empty($c['ig_id']);
    }

    public function isFacebookConfigured(): bool
    {
        if (! (bool) (config('services.meta.enabled') ?? false)) {
            return false;
        }
        $c = $this->getCredentials();
        return $c['token'] !== null && ! empty($c['page_id']);
    }

    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    /* ------------------------------------------------------------------ */
    /*  Instagram Publishing                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Publish a photo to Instagram.
     *
     * @return array{id: string, permalink: string|null}|null
     */
    public function publishToInstagram(string $imageUrl, string $caption, ?string $locationId = null): ?array
    {
        if (! $this->isInstagramConfigured()) {
            $this->lastError = ['message' => 'Instagram not configured'];
            return null;
        }

        $container = $this->createInstagramContainer($imageUrl, $caption, $locationId);
        if (! $container) {
            return null;
        }

        $containerId = $container['id'];
        $creds = $this->getCredentials();
        $token = $creds['token'];
        $igUserId = $creds['ig_id'];

        // Wait for container to be ready (Instagram processes asynchronously)
        if (! $this->waitForContainer($containerId, $token)) {
            return null;
        }

        // Step 2: Publish the container
        $publishResponse = Http::timeout(60)->post(self::GRAPH_BASE . "/{$igUserId}/media_publish", [
            'creation_id' => $containerId,
            'access_token' => $token,
        ]);

        if (! $publishResponse->successful()) {
            $this->lastError = [
                'message' => 'Instagram publish failed',
                'status' => $publishResponse->status(),
                'body' => $publishResponse->json(),
            ];
            Log::error('Meta Social: IG publish failed', $this->lastError);
            return null;
        }

        $mediaId = $publishResponse->json('id');

        // Fetch permalink
        $permalink = $this->getInstagramPermalink($mediaId, $token);

        Log::info('Meta Social: Published to Instagram', [
            'media_id' => $mediaId,
            'permalink' => $permalink,
        ]);

        return [
            'id' => $mediaId,
            'permalink' => $permalink,
        ];
    }

    /**
     * Publish a project image to Instagram, choosing between a single square
     * post or a 2-image left/right carousel based on the source aspect ratio.
     *
     * @return array{id: string, permalink: string|null}|null
     */
    public function publishToInstagramForImage(ProjectImage $image, string $caption): ?array
    {
        $urls = $this->getInstagramImageUrls($image);

        if (empty($urls)) {
            $this->lastError = ['message' => 'No public image URL for image ' . $image->id];
            return null;
        }

        $locationId = $this->findInstagramLocationId($image->project?->location);

        if (count($urls) === 1) {
            return $this->publishToInstagram($urls[0], $caption, $locationId);
        }

        return $this->publishInstagramCarousel($urls, $caption, $locationId);
    }

    /**
     * Publish a multi-image Instagram carousel.
     *
     * @param string[] $imageUrls
     * @return array{id: string, permalink: string|null}|null
     */
    public function publishInstagramCarousel(array $imageUrls, string $caption, ?string $locationId = null): ?array
    {
        if (! $this->isInstagramConfigured()) {
            $this->lastError = ['message' => 'Instagram not configured'];
            return null;
        }

        $creds = $this->getCredentials();
        $igUserId = $creds['ig_id'];
        $token = $creds['token'];

        // 1. Create each child container
        $childIds = [];
        foreach ($imageUrls as $url) {
            $resp = Http::timeout(60)->post(self::GRAPH_BASE . "/{$igUserId}/media", [
                'image_url' => $url,
                'is_carousel_item' => true,
                'access_token' => $token,
            ]);

            if (! $resp->successful() || ! $resp->json('id')) {
                $this->lastError = [
                    'message' => 'IG carousel child creation failed',
                    'status' => $resp->status(),
                    'body' => $resp->json(),
                    'url' => $url,
                ];
                Log::error('Meta Social: IG carousel child failed', $this->lastError);
                return null;
            }

            $childIds[] = $resp->json('id');
        }

        // Wait for each child to finish processing
        foreach ($childIds as $cid) {
            if (! $this->waitForContainer($cid, $token)) {
                return null;
            }
        }

        // 2. Create the parent carousel container
        $parentPayload = [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $childIds),
            'caption' => $caption,
            'access_token' => $token,
        ];

        if ($locationId) {
            $parentPayload['location_id'] = $locationId;
        }

        $parentResp = Http::timeout(60)->post(self::GRAPH_BASE . "/{$igUserId}/media", $parentPayload);

        if (! $parentResp->successful() || ! $parentResp->json('id')) {
            $this->lastError = [
                'message' => 'IG carousel parent creation failed',
                'status' => $parentResp->status(),
                'body' => $parentResp->json(),
            ];
            Log::error('Meta Social: IG carousel parent failed', $this->lastError);
            return null;
        }

        $parentId = $parentResp->json('id');

        if (! $this->waitForContainer($parentId, $token)) {
            return null;
        }

        // 3. Publish
        $publishResp = Http::timeout(60)->post(self::GRAPH_BASE . "/{$igUserId}/media_publish", [
            'creation_id' => $parentId,
            'access_token' => $token,
        ]);

        if (! $publishResp->successful()) {
            $this->lastError = [
                'message' => 'IG carousel publish failed',
                'status' => $publishResp->status(),
                'body' => $publishResp->json(),
            ];
            Log::error('Meta Social: IG carousel publish failed', $this->lastError);
            return null;
        }

        $mediaId = $publishResp->json('id');
        $permalink = $this->getInstagramPermalink($mediaId, $token);

        Log::info('Meta Social: Published IG carousel', [
            'media_id' => $mediaId,
            'children' => count($childIds),
            'permalink' => $permalink,
        ]);

        return ['id' => $mediaId, 'permalink' => $permalink];
    }

    /**
     * Create an Instagram media container without publishing it.
     *
     * This lets us validate credentials/image URL/caption end-to-end while
     * avoiding a public post. Container expires automatically if not published.
     *
     * @return array{id: string}|null
     */
    public function createInstagramContainer(string $imageUrl, string $caption, ?string $locationId = null): ?array
    {
        if (! $this->isInstagramConfigured()) {
            $this->lastError = ['message' => 'Instagram not configured'];
            return null;
        }

        $creds = $this->getCredentials();
        $igUserId = $creds['ig_id'];
        $token = $creds['token'];

        $payload = [
            'image_url' => $imageUrl,
            'caption' => $caption,
            'access_token' => $token,
        ];

        if ($locationId) {
            $payload['location_id'] = $locationId;
        }

        $containerResponse = Http::timeout(60)->post(self::GRAPH_BASE . "/{$igUserId}/media", $payload);

        // Retry without the location tag if Meta rejects the cached IG location ID.
        if (! $containerResponse->successful() && $locationId) {
            $errMsg = (string) ($containerResponse->json('error.message') ?? '');
            if (stripos($errMsg, 'location') !== false) {
                Log::warning('Meta Social: invalidating cached IG location ID', [
                    'location_id' => $locationId,
                    'error' => $errMsg,
                ]);
                \App\Models\AreaServed::where('ig_location_id', $locationId)
                    ->update(['ig_location_id' => null]);

                unset($payload['location_id']);
                $containerResponse = Http::timeout(60)->post(self::GRAPH_BASE . "/{$igUserId}/media", $payload);
            }
        }

        if (! $containerResponse->successful()) {
            $this->lastError = [
                'message' => 'Instagram container creation failed',
                'status' => $containerResponse->status(),
                'body' => $containerResponse->json(),
            ];
            Log::error('Meta Social: IG container failed', $this->lastError);
            return null;
        }

        $containerId = $containerResponse->json('id');
        if (! $containerId) {
            $this->lastError = ['message' => 'No container ID returned'];
            return null;
        }

        return ['id' => $containerId];
    }

    /* ------------------------------------------------------------------ */
    /*  Facebook Publishing                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Publish a photo to a Facebook Page.
     *
     * @return array{id: string, permalink: string|null}|null
     */
    public function publishToFacebook(string $imageUrl, string $message, ?string $facebookPlaceId = null): ?array
    {
        if (! $this->isFacebookConfigured()) {
            $this->lastError = ['message' => 'Facebook not configured'];
            return null;
        }

        $creds = $this->getCredentials();
        $pageId = $creds['page_id'];
        $token = $creds['token'];

        $payload = [
            'url' => $imageUrl,
            'message' => $message,
            'access_token' => $token,
        ];

        if (is_string($facebookPlaceId) && trim($facebookPlaceId) !== '') {
            $payload['place'] = trim($facebookPlaceId);
        }

        $response = Http::timeout(60)->asForm()->post(self::GRAPH_BASE . "/{$pageId}/photos", $payload);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Facebook photo upload failed',
                'status' => $response->status(),
                'body' => $response->json(),
            ];
            Log::error('Meta Social: FB upload failed', $this->lastError);
            return null;
        }

        $postId = $response->json('post_id') ?? $response->json('id');

        // Fetch canonical permalink when available.
        $permalink = $this->getFacebookPermalink((string) $postId, $token)
            ?? "https://www.facebook.com/{$postId}";

        Log::info('Meta Social: Published to Facebook', [
            'post_id' => $postId,
            'permalink' => $permalink,
        ]);

        return [
            'id' => $postId,
            'permalink' => $permalink,
        ];
    }

    protected function getFacebookPermalink(string $postId, string $token): ?string
    {
        if ($postId === '') {
            return null;
        }

        $resp = Http::timeout(20)->get(self::GRAPH_BASE . "/{$postId}", [
            'fields' => 'permalink_url',
            'access_token' => $token,
        ]);

        if (! $resp->successful()) {
            return null;
        }

        $url = (string) ($resp->json('permalink_url') ?? '');
        return $url !== '' ? $url : null;
    }

    /* ------------------------------------------------------------------ */
    /*  Token Management                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Get the access token. Prefers the OAuth-stored credential, falls
     * back to env (legacy).
     */
    protected function getAccessToken(): string
    {
        return (string) ($this->getCredentials()['token'] ?? '');
    }

    /* ------------------------------------------------------------------ */
    /*  Public URL builder for project images                              */
    /* ------------------------------------------------------------------ */

    /**
     * Get a publicly accessible URL for a project image.
     * Instagram requires a publicly reachable URL (not a local file).
     */
    public function getPublicImageUrl(ProjectImage $image): ?string
    {
        // Used by Facebook Graph API: must be a URL reachable on the public site.
        // IG square crops live in `thumbnails.instagram` but are generated locally
        // for puppeteer uploads and aren't deployed to production. Prefer the
        // original `path`, which is always served by production storage.
        $productionUrl = rtrim((string) config('app.url'), '/');
        $path = $image->path
            ?: ($image->thumbnails['large'] ?? null)
            ?: ($image->thumbnails['hero'] ?? null);
        return $path ? $productionUrl . '/storage/' . ltrim($path, '/') : null;
    }

    /**
     * Return the public URL(s) to send to Instagram for this image.
     *
     * Returns a single 1440×1440 square crop for portrait/square/mild-landscape
     * photos. For wide landscape (aspect ≥ 1.25) returns two 1:1 crops (left
     * half + right half) so the post becomes a carousel and no important content
     * is cropped out.
     *
     * @return string[]
     */
    public function getInstagramImageUrls(ProjectImage $image): array
    {
        $productionUrl = (string) config('app.url');
        $imageService = app(\App\Services\ImageService::class);

        $width = (int) ($image->width ?? 0);
        $height = (int) ($image->height ?? 0);
        $aspect = $height > 0 ? $width / $height : 1.0;

        $thumbnails = $image->thumbnails ?? [];

        // Always use a single 1440² center crop (no carousels for now).
        $square = $thumbnails['instagram'] ?? null;
        if (! $square || ! \Illuminate\Support\Facades\Storage::disk('public')->exists($square)) {
            try {
                $imageService->regenerateThumbnails($image, 'instagram', true);
                $image->refresh();
                $square = ($image->thumbnails ?? [])['instagram'] ?? null;
            } catch (\Throwable $e) {
                $square = null;
            }
        }
        $paths = [$square ?? $thumbnails['large'] ?? $thumbnails['hero'] ?? $image->path];

        return array_map(
            fn ($p) => rtrim($productionUrl, '/') . '/storage/' . ltrim($p, '/'),
            $paths,
        );
    }

    /**
     * Build the website link for a project image page.
     */
    public function getProjectPageUrl(ProjectImage $image): string
    {
        $productionUrl = (string) config('app.url');
        $project = $image->project;

        if ($project && $image->slug) {
            return "{$productionUrl}/projects/{$project->slug}/photos/{$image->slug}";
        }

        if ($project) {
            return "{$productionUrl}/projects/{$project->slug}";
        }

        return $productionUrl;
    }

    /**
     * Generate a short link for a project image page URL.
     * Returns a compact URL like https://gs.construction/s/Xk9m2P
     */
    public function getShortLinkUrl(ProjectImage $image): string
    {
        $fullUrl = $this->getProjectPageUrl($image);
        $shortLink = ShortLink::shorten($fullUrl);

        return $shortLink->short_url;
    }

    /* ------------------------------------------------------------------ */
    /*  Instagram helpers                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Resolve an Instagram location ID for a "City, ST" string by looking it
     * up in the areas_served cache (populated by the
     * instagram:resolve-locations artisan command). Returns null if no match
     * is found — the post will publish without a location tag.
     */
    public function findInstagramLocationId(?string $location): ?string
    {
        $location = trim((string) $location);
        if ($location === '') {
            return null;
        }

        // Caller usually passes "Palatine, IL" — match on the city portion.
        $city = trim(explode(',', $location)[0]);
        if ($city === '') {
            return null;
        }

        $slug = \Illuminate\Support\Str::slug($city);

        $area = \App\Models\AreaServed::query()
            ->whereNotNull('ig_location_id')
            ->where(function ($q) use ($city, $slug) {
                $q->where('slug', $slug)
                    ->orWhereRaw('LOWER(city) = ?', [strtolower($city)]);
            })
            ->first();

        return $area?->ig_location_id;
    }

    /**
     * Resolve a Facebook Place ID for a "City, ST" string by looking it up in
     * the areas_served cache. FB Place IDs must be populated manually because
     * the Graph API place-search endpoints require app-review features. Returns
     * null when no match is found — the post will publish without a check-in.
     */
    public function findFacebookPlaceId(?string $location): ?string
    {
        $location = trim((string) $location);
        if ($location === '') {
            return null;
        }

        $city = trim(explode(',', $location)[0]);
        if ($city === '') {
            return null;
        }

        $slug = \Illuminate\Support\Str::slug($city);

        $area = \App\Models\AreaServed::query()
            ->whereNotNull('fb_place_id')
            ->where(function ($q) use ($city, $slug) {
                $q->where('slug', $slug)
                    ->orWhereRaw('LOWER(city) = ?', [strtolower($city)]);
            })
            ->first();

        return $area?->fb_place_id;
    }

    /**
     * Wait for an Instagram media container to finish processing.
     */
    protected function waitForContainer(string $containerId, string $token, int $maxAttempts = 10): bool
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            sleep(3);

            $status = Http::get(self::GRAPH_BASE . "/{$containerId}", [
                'fields' => 'status_code',
                'access_token' => $token,
            ]);

            $statusCode = $status->json('status_code');

            if ($statusCode === 'FINISHED') {
                return true;
            }

            if ($statusCode === 'ERROR') {
                $this->lastError = [
                    'message' => 'Container processing failed',
                    'body' => $status->json(),
                ];
                Log::error('Meta Social: Container error', $this->lastError);
                return false;
            }

            // IN_PROGRESS — keep waiting
        }

        $this->lastError = ['message' => 'Container processing timed out'];
        Log::warning('Meta Social: Container timed out', ['container_id' => $containerId]);
        return false;
    }

    protected function getInstagramPermalink(string $mediaId, string $token): ?string
    {
        $response = Http::get(self::GRAPH_BASE . "/{$mediaId}", [
            'fields' => 'permalink',
            'access_token' => $token,
        ]);

        return $response->json('permalink');
    }

    /* ------------------------------------------------------------------ */
    /*  Debug / Health                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Verify the token is valid and check which pages/IG accounts are available.
     */
    public function debugTokenInfo(): array
    {
        $token = $this->getAccessToken();

        $debug = Http::get(self::GRAPH_BASE . '/debug_token', [
            'input_token' => $token,
            'access_token' => $token,
        ]);

        $pages = Http::get(self::GRAPH_BASE . '/me/accounts', [
            'access_token' => $token,
        ]);

        return [
            'token_debug' => $debug->json(),
            'pages' => $pages->json('data', []),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Admin OAuth (Facebook Login flow)                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Build the Facebook Login dialog URL for the admin to authorise the
     * app against their Facebook user / page / linked Instagram account.
     */
    public function getOAuthUrl(string $redirectUri, ?string $state = null): string
    {
        $params = http_build_query([
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(',', self::OAUTH_SCOPES),
            'state' => $state ?: bin2hex(random_bytes(8)),
            'auth_type' => 'rerequest',
        ]);

        return 'https://www.facebook.com/v25.0/dialog/oauth?' . $params;
    }

    /**
     * Exchange the OAuth code for a long-lived Page Access Token,
     * auto-discover the FB Page + linked Instagram Business account,
     * and persist everything into oauth_tokens.
     *
     * @return array{success: bool, error?: string, page_name?: string, ig_username?: ?string}
     */
    public function exchangeCodeAndStore(string $code, string $redirectUri): array
    {
        $appId = config('services.meta.app_id');
        $appSecret = config('services.meta.app_secret');
        if (! $appId || ! $appSecret) {
            return ['success' => false, 'error' => 'META_APP_ID / META_APP_SECRET not configured in .env'];
        }

        // 1. Code -> short-lived user access token
        $tokenResp = Http::timeout(20)->get(self::GRAPH_BASE . '/oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);
        if (! $tokenResp->successful()) {
            return ['success' => false, 'error' => 'Code exchange failed: ' . ($tokenResp->json('error.message') ?? $tokenResp->body())];
        }
        $shortLivedUserToken = $tokenResp->json('access_token');

        // 2. Short-lived -> long-lived user token (~60d)
        $longResp = Http::timeout(20)->get(self::GRAPH_BASE . '/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $shortLivedUserToken,
        ]);
        if (! $longResp->successful()) {
            return ['success' => false, 'error' => 'Long-lived token exchange failed: ' . ($longResp->json('error.message') ?? $longResp->body())];
        }
        $longLivedUserToken = $longResp->json('access_token');

        // 3. Fetch user's pages -> page access tokens (these don't expire
        //    while the user keeps the app authorised).
        $pagesResp = Http::timeout(20)->get(self::GRAPH_BASE . '/me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'access_token' => $longLivedUserToken,
        ]);
        if (! $pagesResp->successful()) {
            return ['success' => false, 'error' => 'Failed to list pages: ' . ($pagesResp->json('error.message') ?? $pagesResp->body())];
        }

        $pages = $pagesResp->json('data', []);
        if (empty($pages)) {
            return ['success' => false, 'error' => 'This account does not manage any Facebook Pages. Make sure the page admin authorises the app.'];
        }

        // Prefer the page with a linked Instagram Business account; fall back to the first.
        $page = null;
        foreach ($pages as $p) {
            if (! empty($p['instagram_business_account']['id'] ?? null)) { $page = $p; break; }
        }
        $page = $page ?? $pages[0];

        // 4. Identify the authorising user (for the granted_by_email column)
        $meResp = Http::timeout(10)->get(self::GRAPH_BASE . '/me', [
            'fields' => 'id,name,email',
            'access_token' => $longLivedUserToken,
        ]);
        $email = $meResp->successful() ? ($meResp->json('email') ?? $meResp->json('name')) : null;

        // 5. Persist
        OAuthToken::updateOrCreate(
            ['provider' => self::OAUTH_PROVIDER],
            [
                'access_token' => $page['access_token'],
                'refresh_token' => $longLivedUserToken, // kept for re-issuing page tokens later
                'access_token_expires_at' => null, // page tokens issued from long-lived user tokens don't expire
                'scopes' => self::OAUTH_SCOPES,
                'granted_by_email' => $email,
                'metadata' => [
                    'page_id' => $page['id'],
                    'page_name' => $page['name'] ?? null,
                    'ig_id' => $page['instagram_business_account']['id'] ?? null,
                    'ig_username' => $page['instagram_business_account']['username'] ?? null,
                ],
            ],
        );

        return [
            'success' => true,
            'page_name' => $page['name'] ?? '',
            'ig_username' => $page['instagram_business_account']['username'] ?? null,
        ];
    }

    /**
     * Forget the stored Meta credentials.
     */
    public function disconnect(): void
    {
        OAuthToken::where('provider', self::OAUTH_PROVIDER)->delete();
    }
}
