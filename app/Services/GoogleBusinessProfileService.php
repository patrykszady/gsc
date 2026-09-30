<?php

namespace App\Services;

use App\Models\AreaServed;
use App\Models\OAuthToken;
use App\Models\ProjectImage;
use DateTimeInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SsSystems\Platform\Google\BusinessProfile\Client;
use SsSystems\Platform\Google\BusinessProfile\Contracts\ListingStore;
use SsSystems\Platform\Google\Contracts\TokenStore;
use SsSystems\Platform\Google\OAuthClient;
use SsSystems\Platform\Media\GooglePhotoCopy;

/**
 * gs.construction's Google Business Profile service — since kit 0.14.0 a
 * subclass of the ONE Business Profile implementation every tenant runs
 * (`SsSystems\Platform\Google\BusinessProfile\Client`, see the kit's
 * docs/GOOGLE.md). The grant, the access token (persisted, rotated, scope-
 * repaired, the invalid_grant cooldown, a grant from another OAuth client
 * forgotten), and every Google call — accounts, locations, reviews, media,
 * local posts, location patches — are the kit's. What stays here is only
 * gs.construction's own business:
 *
 * - the project-photo copy Google fetches (`getPublicImageUrl()`, the kit's
 *   `Media\GooglePhotoCopy`), its category and caption;
 * - the service-area geocoding and the service items, built into
 *   `updateLocation()` payloads (as are the categories and the description
 *   the SEO autopilot writes);
 * - the Places API review read (`fetchPlaceReviews()`);
 * - the self-scoped wrappers its ~25 commands, jobs and observers call —
 *   `createLocalPost()`, `fetchAllReviews()`, `uploadProjectImage()`,
 *   `listMedia()`, … — each delegating to the kit's `…For()` method with the
 *   listing this site chose (`ListingStore`), gated on `isConfigured()`
 *   exactly as before, and failing with the same `getLastError()['message']`
 *   the callers already store or print.
 *
 * Tenancy: bound per resolution in AppServiceProvider, never a singleton —
 * the grant is read through `OAuthToken` (BelongsToSite) on every call, the
 * listing through `PlatformSetting` (BelongsToSite), and the env refresh
 * token and listing ids are passed in for the default site only. The kit
 * derives every cache key from the grant's own refresh token, which retired
 * the single `google_business_profile_access_token` key every tenant shared.
 */
class GoogleBusinessProfileService extends Client
{
    public function __construct(
        OAuthClient $oauth,
        TokenStore $tokens,
        CacheInterface $cache,
        Factory $http,
        ?LoggerInterface $log,
        ?string $fallbackRefreshToken,
        protected readonly ListingStore $listing,
    ) {
        parent::__construct($oauth, $tokens, $cache, $http, $log, $fallbackRefreshToken);
    }

    /* ------------------------------------------------------------------ */
    /*  The listing this site chose */
    /* ------------------------------------------------------------------ */

    public function listing(): ListingStore
    {
        return $this->listing;
    }

    /** The chosen listing's account id (stored, else the default site's env value). */
    public function accountId(): ?string
    {
        return $this->listing->selected()['account_id'];
    }

    /** The chosen listing's location id (stored, else the default site's env value). */
    public function locationId(): ?string
    {
        return $this->listing->selected()['location_id'];
    }

    /* ------------------------------------------------------------------ */
    /*  Readiness */
    /* ------------------------------------------------------------------ */

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
     * Signed in with a listing chosen: the shared OAuth client, a grant, and
     * an account and location. Deliberately stricter than the kit's own
     * isConnected() (a grant alone) — every self-scoped call below needs the
     * listing too, and the schedules in routes/console.php gate on this.
     */
    public function isConnected(): bool
    {
        if (! $this->oauth->isConfigured() || ! $this->hasRefreshToken()) {
            return false;
        }

        $listing = $this->listing->selected();

        return ! empty($listing['account_id']) && ! empty($listing['location_id']);
    }

    /** The shared OAuth client plus a grant (no listing needed yet). */
    public function hasOAuthCredentials(): bool
    {
        return $this->oauth->isConfigured() && $this->hasRefreshToken();
    }

    /** The refresh token in use: the stored grant's, else (default site only) the env one. */
    public function getRefreshToken(): ?string
    {
        return $this->refreshToken();
    }

    /** The DB token row (if any), for the screens that print who granted it and when. */
    public function getStoredToken(): ?OAuthToken
    {
        return OAuthToken::forProvider(self::PROVIDER);
    }

    /**
     * Evict the cached access token (the kit's key for this grant), so the
     * next getAuthorizedToken() does not serve it from cache — what the
     * Performance service does after a 401 from its own endpoints.
     */
    public function forgetCachedAccessToken(): void
    {
        $refreshToken = $this->refreshToken();

        if ($refreshToken !== null) {
            $this->cache->delete($this->key('access_token', $refreshToken));
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Project photos */
    /* ------------------------------------------------------------------ */

    /**
     * Upload a project image to this site's own listing. Returns the created
     * media item's `name` (resource name) and `url` (the full-size
     * googleusercontent rendition) so callers can persist both.
     *
     * @return array{name: string, url: ?string, google_url: ?string, raw: array<string, mixed>}|null
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

        $result = $this->uploadMediaFor((string) $this->accountId(), (string) $this->locationId(), $imageUrl, $this->mapCategory($image), $this->buildDescription($image));

        if ($result) {
            Log::channel('gbp')->info('GBP: Uploaded image', [
                'image_id' => $image->id,
                'media_name' => $result['name'],
                'has_url' => $result['url'] !== null,
            ]);
        } else {
            $this->relabel('Upload media failed', 'Upload failed');
        }

        return $result;
    }

    /** Delete a media item from Google (a 404 — already gone — counts as deleted). */
    public function deleteMedia(string $mediaName): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $deleted = $this->deleteMediaFor($mediaName);

        if (! $deleted) {
            $this->relabel('Delete media failed', 'Delete failed');
        }

        return $deleted;
    }

    /** Fetch a media item from Google. */
    public function getMediaItem(string $mediaName): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        return parent::getMediaItem($mediaName);
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
     * Every media item on one listing, flattened for ss.systems — all pages
     * or nothing, as before 0.14. The kit keeps the pages it read before a
     * later page fails and returns them as a success, but GET
     * platforms/gbp/media is how ss.systems' UploadImageToGbpListing decides
     * whether a photo already reached Google: a partial list answered 200
     * would send a duplicate upload where the old all-or-nothing 422 marked
     * the row failed. A failed page is a failure here (null, getLastError()
     * set), so the pass-through answers 422 as it always did.
     *
     * @return list<array{name: string, source_url: ?string, google_url: ?string, category: ?string, create_time: ?string}>|null
     */
    public function listMediaFor(string $accountId, string $locationId): ?array
    {
        $items = parent::listMediaFor($accountId, $locationId);

        return $this->lastError === null ? $items : null;
    }

    /**
     * One page of the media on this site's own listing, as Google returns it
     * (`mediaItems`, `nextPageToken`). listMediaFor() is the any-listing,
     * all-pages, flattened sibling the central admin's pass-through reads.
     */
    public function listMedia(?string $pageToken = null, int $pageSize = 100): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $response = $this->send('GET', $this->locationBaseUrl().'/media', array_filter([
            'pageSize' => $pageSize,
            'pageToken' => $pageToken,
        ]), 30);

        if (! $this->ok($response, 'List media failed')) {
            return null;
        }

        return (array) $response->json();
    }

    /**
     * List ALL media items on this site's own listing (auto-paginating).
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

    /* ------------------------------------------------------------------ */
    /*  Local posts ("Updates" on the listing) */
    /* ------------------------------------------------------------------ */

    /**
     * Create a Local Post on this site's own listing — the kit's
     * createLocalPostFor() with the chosen listing: a photo Google fetches,
     * the summary cut to 1,500 characters, a CTA button, STANDARD topic.
     *
     * @return array{name: string, searchUrl: ?string, state: ?string}|null
     */
    public function createLocalPost(string $imageUrl, string $summary, string $ctaUrl, string $ctaType = 'LEARN_MORE'): ?array
    {
        if (! $this->isConfigured()) {
            $this->lastError = ['message' => 'GBP not configured'];

            return null;
        }

        $result = $this->createLocalPostFor((string) $this->accountId(), (string) $this->locationId(), $imageUrl, $summary, $ctaUrl, $ctaType);

        if ($result === null) {
            $this->relabel('Create post failed', 'GBP local post failed');
        }

        return $result;
    }

    /**
     * Delete a single local post ("update") from the listing. A post Google
     * no longer has (404) counts as deleted.
     *
     * @param  string  $postName  Full resource name: accounts/{a}/locations/{l}/localPosts/{p}
     */
    public function deleteLocalPost(string $postName): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        return parent::deleteLocalPost($postName);
    }

    /* ------------------------------------------------------------------ */
    /*  Reviews */
    /* ------------------------------------------------------------------ */

    /**
     * One page of this site's own listing's reviews.
     *
     * @return array{reviews: array, totalReviewCount: int, averageRating: float, nextPageToken: ?string}|null
     */
    public function fetchReviews(?string $pageToken = null, int $pageSize = 50): ?array
    {
        if (! $this->isConfigured()) {
            $this->lastError = ['message' => 'GBP not configured'];

            return null;
        }

        return $this->fetchReviewsFor((string) $this->accountId(), (string) $this->locationId(), $pageToken, $pageSize);
    }

    /**
     * Fetch ALL reviews of this site's own listing (auto-paginating). What
     * was read before a failing page is kept, as before.
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

    /** Reply to (or update the owner reply on) one review. */
    public function replyToReview(string $reviewName, string $comment): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $result = parent::replyToReview($reviewName, $comment);

        if ($result !== null) {
            Log::channel('gbp')->info('GBP: Replied to review', ['review' => $reviewName]);
        }

        return $result;
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
        $placeId = $this->listing->placeId();
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

    /* ------------------------------------------------------------------ */
    /*  The Google copy of a project photo */
    /* ------------------------------------------------------------------ */

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
    public function getPublicImageUrl(ProjectImage $image, ?float $latitude = null, ?float $longitude = null, ?DateTimeInterface $takenAt = null, bool $cover = false): ?string
    {
        // Build URL using the production domain
        $productionUrl = config('services.google.business_profile.production_url')
            ?: config('app.url');

        // GBP expects JPG; generate a full-size JPG copy for uploads.
        $relativeUrl = $this->getGbpJpegUrl($image, $latitude, $longitude, $takenAt, $cover)
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
     *
     * $cover makes the listing's COVER copy instead (kit 0.14.1,
     * 2026-09-29): cropped to 16:9 within Google's 2120×1192 cover limit,
     * named `{name}-gbp-cover-{fingerprint}.jpg` beside the photo copy.
     */
    protected function getGbpJpegUrl(ProjectImage $image, ?float $latitude = null, ?float $longitude = null, ?DateTimeInterface $takenAt = null, bool $cover = false): ?string
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
        $fingerprint = GooglePhotoCopy::fingerprint($sourceKey, $latitude, $longitude, $takenAt, $cover);
        $jpgPath = ($dir !== '' ? $dir.'/' : '').$name.($cover ? '-gbp-cover-' : '-gbp-').$fingerprint.'.jpg';

        if (! Storage::disk($disk)->exists($jpgPath)) {
            try {
                $sourceBytes = Storage::disk($disk)->get($path);
                $jpeg = $cover
                    ? GooglePhotoCopy::makeCover($sourceBytes, $latitude, $longitude, $takenAt)
                    : GooglePhotoCopy::make($sourceBytes, $latitude, $longitude, $takenAt);

                Storage::disk($disk)->put($jpgPath, $jpeg);
                $this->removeStaleGbpCopies($disk, $dir, $name, $jpgPath, $cover);
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
    protected function removeStaleGbpCopies(string $disk, string $dir, string $name, string $keepPath, bool $cover = false): void
    {
        $pattern = $cover
            ? '/^'.preg_quote($name, '/').'-gbp-cover-[0-9a-f]+\.jpg$/i'
            : '/^'.preg_quote($name, '/').'(-gbp-[0-9a-f]+|_gbp)\.jpg$/i';

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
     * Public (2026-09-21): the admin API's media pass-through defaults its
     * optional `category` param to this, same as uploadProjectImage() does.
     */
    public function mapCategory(ProjectImage $image): string
    {
        // Some locations do not allow certain categories (INTERIOR/EXTERIOR
        // are storefront tags a service-area listing refuses with "Photo tag
        // 'interior' does not apply"), so every project photo is ADDITIONAL.
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

    /* ------------------------------------------------------------------ */
    /*  The listing's profile: read, and the payloads gs.construction */
    /*  builds for the kit's updateLocation() */
    /* ------------------------------------------------------------------ */

    /**
     * This site's own listing as the Business Information API returns it.
     * (The kit's getLocation($locationId, $readMask) reads any listing.)
     */
    public function getListingLocation(string $readMask = 'name,title,categories,serviceArea,websiteUri'): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        return $this->getLocation((string) $this->locationId(), $readMask);
    }

    /**
     * Fetch the Place ID for the configured GBP location via the Business Information API.
     * The metadata.placeId field is the official Place ID (ChIJ...) for use with the Places API.
     */
    public function fetchPlaceId(): ?string
    {
        $data = $this->getListingLocation('metadata');

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

        // Nothing is geocoded for a grant that cannot act (as before).
        if ($this->accessToken() === null) {
            return null;
        }

        // Fetch current profile for auto-detection of business type
        $current = $this->getListingLocation('serviceArea');

        if (! $businessType) {
            // "Keep the current type" needs the current type. A read that
            // failed must not fall back to CUSTOMER_LOCATION_ONLY and rewrite
            // it on the live listing — until kit 0.14 a Google that did not
            // answer threw here; the kit's client returns null instead. (A
            // listing with no service area reads back empty with no error,
            // and still takes the default, as before.)
            if ($current === null && $this->lastError !== null) {
                return null;
            }

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

        Log::channel('gbp')->debug('GBP: Service area update request', [
            'url' => $this->infoLocationUrl().'?updateMask=serviceArea',
            'business_type' => $businessType,
            'cities_count' => count($placeInfos),
            'sample_place' => $placeInfos[0] ?? null,
        ]);

        $data = $this->updateLocation((string) $this->locationId(), 'serviceArea', $payload);

        if ($data === null) {
            $this->relabel('Update location failed', 'Update service area failed');

            return null;
        }

        Log::channel('gbp')->info('GBP: Updated service area', [
            'cities_count' => count($placeInfos),
            'business_type' => $businessType,
        ]);

        return $data;
    }

    /**
     * Public wrapper so commands can preview place-ID resolution for a service
     * area (town or county) before writing to the live profile.
     */
    public function resolveServiceAreaPlaceId(string $name): ?string
    {
        return $this->resolveGeocodePlaceId($name);
    }

    /**
     * Resolve a city/place name to a Google Place ID — a city- or county-
     * level region, or null if not found.
     */
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

        $data = $this->updateLocation((string) $this->locationId(), 'categories', $payload);

        if ($data === null) {
            $this->relabel('Update location failed', 'Update categories failed');

            return null;
        }

        Log::channel('gbp')->info('GBP: Updated categories', [
            'primary' => $primaryCategoryId,
            'additional' => $additionalCategoryIds,
        ]);

        return $data;
    }

    /** The "From the business" description on the listing, or null when unreadable. */
    public function getDescription(): ?string
    {
        $location = $this->getListingLocation('profile');
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

        $description = mb_substr(trim($description), 0, 750);

        $data = $this->updateLocation((string) $this->locationId(), 'profile.description', ['profile' => ['description' => $description]]);

        if ($data === null) {
            $this->relabel('Update location failed', 'Update description failed');

            return null;
        }

        Log::channel('gbp')->info('GBP: Updated description', ['length' => mb_strlen($description)]);

        return $data;
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

        $data = $this->updateLocation((string) $this->locationId(), 'serviceItems', ['serviceItems' => $serviceItems]);

        if ($data === null) {
            $this->relabel('Update location failed', 'Update service items failed');

            return null;
        }

        Log::channel('gbp')->info('GBP: Updated service items', ['count' => count($serviceItems)]);

        return $data;
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /** The v4 base for this site's own listing (media, local posts, reviews). */
    protected function locationBaseUrl(): string
    {
        return self::MEDIA_API_BASE.'/accounts/'.self::bareId((string) $this->accountId()).'/locations/'.self::bareId((string) $this->locationId());
    }

    /** The Business Information URL of this site's own listing. */
    protected function infoLocationUrl(): string
    {
        return self::INFO_API_BASE.'/locations/'.self::bareId((string) $this->locationId());
    }

    /**
     * Keep the `message` this site's callers have always stored and printed
     * ("GBP local post failed (status 403)" on a failed social post, the
     * autopilot's "Google rejected the description: {…}") when the kit's
     * generic one names the same Google failure. Only an API failure is
     * renamed; a token failure keeps its own message, as before.
     */
    protected function relabel(string $kitMessage, string $message): void
    {
        if (($this->lastError['message'] ?? null) === $kitMessage) {
            $this->lastError['message'] = $message;
        }
    }
}
