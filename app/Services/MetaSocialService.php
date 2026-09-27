<?php

namespace App\Services;

use App\Models\AreaServed;
use App\Models\ProjectImage;
use App\Models\ShortLink;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SsSystems\Platform\Social\Contracts\MetaCredentialStore;
use SsSystems\Platform\Social\MetaGraphClient;

/**
 * gsc's Meta Graph API surface — a thin subclass of the kit's
 * `SsSystems\Platform\Social\MetaGraphClient` (2026-09-27; see
 * ss-platform-kit/docs/audit-2026-09-27/social-posting-and-automation.md
 * unit 9, and CONSOLIDATION-PLAN.md's Kit 0.13.0 entry). The OAuth
 * connect/disconnect dance and the single-image Instagram/Facebook publish
 * path now live in the kit; this class carries only what is genuinely
 * gsc's own:
 *   - the OAUTH_SCOPES list (one of THREE distinct production sets — see
 *     MetaGraphClient::getOAuthUrl()'s docblock; jpeterson shares this
 *     one, hive's own is different, dawnsellshomes' will be a 6-scope
 *     superset),
 *   - Instagram carousels, Instagram location tagging / Facebook
 *     check-ins (both AreaServed-backed), the hourly stale-location-id
 *     metric, and the debug/health endpoint — confirmed gsc-only; NOT
 *     ported anywhere else (jpeterson's own trimmed copy says explicitly
 *     which of these it chose not to carry),
 *   - the multi-crop `getInstagramImageUrls()` picker and the
 *     `publishToInstagramForImage()`/`getPublicImageUrl()`/
 *     `getProjectPageUrl()`/`getShortLinkUrl()` domain wrappers, which
 *     need gsc's own `ProjectImage`/`ShortLink` models — a kit class
 *     never references `App\Models\*`.
 *
 * Credential storage: `MetaCredentialStore` is bound to
 * `SsSystems\Platform\Social\Adapters\OAuthTokenCredentialStore` wrapping
 * gsc's own `App\Models\OAuthToken` (see AppServiceProvider) — its
 * `use BelongsToSite;` tenant scope keeps applying exactly as before,
 * since the adapter only ever calls that class's own static methods.
 */
class MetaSocialService extends MetaGraphClient
{
    /**
     * Scopes required for publishing to the linked Instagram Business
     * account. We intentionally do NOT request Facebook Page posting
     * scopes (pages_manage_posts, pages_read_engagement) — those require
     * App Review and we only publish to Instagram.
     *
     * Public (not protected): `MetaGraphClient::getOAuthUrl()`/
     * `exchangeCodeAndStore()` take `$scopes` as a call-time argument and
     * are NOT re-declared here at a narrower arity — PHP's method-override
     * compatibility rules refuse a child signature with fewer parameters
     * than the parent's, defaults notwithstanding (see MetaGraphClient::
     * getOAuthUrl()'s docblock). Every call site below passes this
     * constant explicitly instead.
     */
    public const OAUTH_SCOPES = [
        'pages_show_list',
        'business_management',
        'instagram_basic',
        'instagram_content_publish',
    ];

    public function __construct(MetaCredentialStore $credentials)
    {
        parent::__construct(
            $credentials,
            appId: config('services.meta.app_id'),
            appSecret: config('services.meta.app_secret'),
            publishingEnabled: (bool) (config('services.meta.enabled') ?? false),
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Instagram Publishing (gsc-only extras) */
    /* ------------------------------------------------------------------ */

    /**
     * Publish a project image to Instagram, choosing between a single
     * square post or a 2-image left/right carousel based on the source aspect ratio.
     *
     * @return array{id: string, permalink: string|null}|null
     */
    public function publishToInstagramForImage(ProjectImage $image, string $caption): ?array
    {
        $urls = $this->getInstagramImageUrls($image);

        if (empty($urls)) {
            $this->lastError = ['message' => 'No public image URL for image '.$image->id];

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
     * @param  string[]  $imageUrls
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
            $resp = Http::timeout(60)->post(self::GRAPH_BASE."/{$igUserId}/media", [
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
                Log::channel('social')->error('Meta Social: IG carousel child failed', $this->lastError);

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

        $parentResp = $this->createInstagramMediaWithLocationFallback(
            (string) $igUserId,
            (string) $token,
            $parentPayload,
            $locationId,
        );

        if (! $parentResp->successful() || ! $parentResp->json('id')) {
            $this->lastError = [
                'message' => 'IG carousel parent creation failed',
                'status' => $parentResp->status(),
                'body' => $parentResp->json(),
            ];
            Log::channel('social')->error('Meta Social: IG carousel parent failed', $this->lastError);

            return null;
        }

        $parentId = $parentResp->json('id');

        if (! $this->waitForContainer($parentId, $token)) {
            return null;
        }

        // 3. Publish
        $publishResp = Http::timeout(60)->post(self::GRAPH_BASE."/{$igUserId}/media_publish", [
            'creation_id' => $parentId,
            'access_token' => $token,
        ]);

        if (! $publishResp->successful()) {
            $this->lastError = [
                'message' => 'IG carousel publish failed',
                'status' => $publishResp->status(),
                'body' => $publishResp->json(),
            ];
            Log::channel('social')->error('Meta Social: IG carousel publish failed', $this->lastError);

            return null;
        }

        $mediaId = $publishResp->json('id');
        $permalink = $this->getInstagramPermalink($mediaId, $token);

        Log::channel('social')->info('Meta Social: Published IG carousel', [
            'media_id' => $mediaId,
            'children' => count($childIds),
            'permalink' => $permalink,
        ]);

        return ['id' => $mediaId, 'permalink' => $permalink];
    }

    /**
     * Create an Instagram media container without publishing it — gsc's
     * own override adds the stale-cached-location-id retry
     * (createInstagramMediaWithLocationFallback) that jpeterson/hive never
     * carried.
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

        $containerResponse = $this->createInstagramMediaWithLocationFallback(
            (string) $igUserId,
            (string) $token,
            $payload,
            $locationId,
        );

        if (! $containerResponse->successful()) {
            $this->lastError = [
                'message' => 'Instagram container creation failed',
                'status' => $containerResponse->status(),
                'body' => $containerResponse->json(),
            ];
            Log::channel('social')->error('Meta Social: IG container failed', $this->lastError);

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
    /*  Public URL builder for project images */
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

        return $path ? $productionUrl.'/storage/'.ltrim($path, '/') : null;
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
        $imageService = app(ImageService::class);

        $width = (int) ($image->width ?? 0);
        $height = (int) ($image->height ?? 0);
        $aspect = $height > 0 ? $width / $height : 1.0;

        $thumbnails = $image->thumbnails ?? [];

        // Always use a single 1440² center crop (no carousels for now).
        $square = $thumbnails['instagram'] ?? null;
        if (! $square || ! Storage::disk('public')->exists($square)) {
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
            fn ($p) => rtrim($productionUrl, '/').'/storage/'.ltrim($p, '/'),
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
    /*  Instagram helpers (gsc-only: AreaServed-backed) */
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

        $slug = Str::slug($city);

        $area = AreaServed::query()
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

        $slug = Str::slug($city);

        $area = AreaServed::query()
            ->whereNotNull('fb_place_id')
            ->where(function ($q) use ($city, $slug) {
                $q->where('slug', $slug)
                    ->orWhereRaw('LOWER(city) = ?', [strtolower($city)]);
            })
            ->first();

        return $area?->fb_place_id;
    }

    /**
     * Create an Instagram media resource with safe fallback when a cached
     * location ID is no longer valid.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function createInstagramMediaWithLocationFallback(string $igUserId, string $token, array $payload, ?string $locationId): Response
    {
        $response = Http::timeout(60)->post(self::GRAPH_BASE."/{$igUserId}/media", $payload);

        if ($response->successful() || ! $locationId) {
            return $response;
        }

        if (! $this->isInvalidInstagramLocationIdError($response)) {
            return $response;
        }

        $metricCount = $this->bumpHourlyMetric('meta_social.stale_ig_location_id');

        Log::channel('social')->warning('Meta Social: invalidating cached IG location ID', [
            'location_id' => $locationId,
            'error' => (string) ($response->json('error.message') ?? ''),
            'metric' => 'meta_social.stale_ig_location_id',
            'metric_count_hour' => $metricCount,
        ]);

        AreaServed::where('ig_location_id', $locationId)
            ->update(['ig_location_id' => null]);

        unset($payload['location_id']);

        return Http::timeout(60)->post(self::GRAPH_BASE."/{$igUserId}/media", $payload);
    }

    protected function isInvalidInstagramLocationIdError(Response $response): bool
    {
        $errorCode = (int) ($response->json('error.code') ?? 0);
        $errorMessage = (string) ($response->json('error.message') ?? '');

        if ($errorCode !== 100) {
            return false;
        }

        return str_contains(strtolower($errorMessage), 'not a valid location page id');
    }

    /**
     * Check whether a cached location_id is usable as an Instagram Graph API
     * media location tag. The Graph /media endpoint only accepts a Facebook
     * Page place ID; Instagram location PKs are rejected. We read the node
     * directly and treat it as valid only when it resolves to a place/page
     * (has a `location` field or `category`), which mirrors what /media accepts.
     *
     * @return bool true when the ID resolves to a valid place page
     */
    public function isUsableInstagramLocationId(string $locationId): bool
    {
        $locationId = trim($locationId);
        if ($locationId === '') {
            return false;
        }

        $creds = $this->getCredentials();
        $token = $creds['token'];
        if (! $token) {
            return false;
        }

        $resp = Http::timeout(20)->get(self::GRAPH_BASE."/{$locationId}", [
            'fields' => 'id,name,location,category',
            'access_token' => $token,
        ]);

        if (! $resp->successful() || $resp->json('error')) {
            return false;
        }

        // A real place page exposes a location object or a category; an IG
        // location PK either errors or returns a node without these.
        return $resp->json('location') !== null || $resp->json('category') !== null;
    }

    protected function bumpHourlyMetric(string $metric): int
    {
        $bucket = now()->format('YmdH');
        $key = "metrics:{$metric}:{$bucket}";

        Cache::add($key, 0, now()->addHours(30));
        $value = Cache::increment($key);

        if (is_int($value)) {
            return $value;
        }

        return (int) Cache::get($key, 0);
    }

    /* ------------------------------------------------------------------ */
    /*  Debug / Health */
    /* ------------------------------------------------------------------ */

    /**
     * Verify the token is valid and check which pages/IG accounts are available.
     */
    public function debugTokenInfo(): array
    {
        $token = $this->getAccessToken();

        $debug = Http::get(self::GRAPH_BASE.'/debug_token', [
            'input_token' => $token,
            'access_token' => $token,
        ]);

        $pages = Http::get(self::GRAPH_BASE.'/me/accounts', [
            'access_token' => $token,
        ]);

        return [
            'token_debug' => $debug->json(),
            'pages' => $pages->json('data', []),
        ];
    }
}
