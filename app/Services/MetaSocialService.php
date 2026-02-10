<?php

namespace App\Services;

use App\Models\ProjectImage;
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
    protected const GRAPH_BASE = 'https://graph.facebook.com/v21.0';

    protected ?array $lastError = null;

    /* ------------------------------------------------------------------ */
    /*  Configuration helpers                                              */
    /* ------------------------------------------------------------------ */

    public function isInstagramConfigured(): bool
    {
        $cfg = config('services.meta');

        return (bool) ($cfg['enabled'] ?? false)
            && ! empty($cfg['page_access_token'])
            && ! empty($cfg['instagram_account_id']);
    }

    public function isFacebookConfigured(): bool
    {
        $cfg = config('services.meta');

        return (bool) ($cfg['enabled'] ?? false)
            && ! empty($cfg['page_access_token'])
            && ! empty($cfg['facebook_page_id']);
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
    public function publishToInstagram(string $imageUrl, string $caption): ?array
    {
        if (! $this->isInstagramConfigured()) {
            $this->lastError = ['message' => 'Instagram not configured'];
            return null;
        }

        $igUserId = config('services.meta.instagram_account_id');
        $token = $this->getAccessToken();

        // Step 1: Create media container
        $containerResponse = Http::timeout(60)->post(self::GRAPH_BASE . "/{$igUserId}/media", [
            'image_url' => $imageUrl,
            'caption' => $caption,
            'access_token' => $token,
        ]);

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

    /* ------------------------------------------------------------------ */
    /*  Facebook Publishing                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Publish a photo to a Facebook Page.
     *
     * @return array{id: string, permalink: string|null}|null
     */
    public function publishToFacebook(string $imageUrl, string $message): ?array
    {
        if (! $this->isFacebookConfigured()) {
            $this->lastError = ['message' => 'Facebook not configured'];
            return null;
        }

        $pageId = config('services.meta.facebook_page_id');
        $token = $this->getAccessToken();

        $response = Http::timeout(60)->post(self::GRAPH_BASE . "/{$pageId}/photos", [
            'url' => $imageUrl,
            'message' => $message,
            'access_token' => $token,
        ]);

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

        // Build permalink
        $permalink = "https://www.facebook.com/{$postId}";

        Log::info('Meta Social: Published to Facebook', [
            'post_id' => $postId,
            'permalink' => $permalink,
        ]);

        return [
            'id' => $postId,
            'permalink' => $permalink,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Token Management                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Get the access token (from config; cached long-lived token).
     */
    protected function getAccessToken(): string
    {
        return config('services.meta.page_access_token');
    }

    /**
     * Exchange a short-lived user token for a long-lived token,
     * then get a permanent Page Access Token.
     *
     * Run once during setup via: php artisan social:meta-auth
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): ?string
    {
        $response = Http::get(self::GRAPH_BASE . '/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'Token exchange failed',
                'body' => $response->json(),
            ];
            return null;
        }

        $longLivedUserToken = $response->json('access_token');

        // Now get the permanent Page Access Token
        $pageId = config('services.meta.facebook_page_id');
        $pagesResponse = Http::get(self::GRAPH_BASE . "/me/accounts", [
            'access_token' => $longLivedUserToken,
        ]);

        if (! $pagesResponse->successful()) {
            $this->lastError = [
                'message' => 'Failed to get page tokens',
                'body' => $pagesResponse->json(),
            ];
            return null;
        }

        $pages = $pagesResponse->json('data', []);
        foreach ($pages as $page) {
            if ($page['id'] === $pageId) {
                return $page['access_token']; // This is a permanent Page Access Token
            }
        }

        $this->lastError = ['message' => "Page ID {$pageId} not found in your pages"];
        return null;
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
        $productionUrl = config('services.meta.production_url', 'https://gs.construction');

        // Prefer the large thumbnail for social (2400×1350 — great for IG)
        $thumbnails = $image->thumbnails ?? [];
        $path = $thumbnails['large'] ?? $thumbnails['hero'] ?? $image->path;

        // Build absolute public URL
        $relativePath = 'storage/' . ltrim($path, '/');

        return rtrim($productionUrl, '/') . '/' . $relativePath;
    }

    /**
     * Build the website link for a project image page.
     */
    public function getProjectPageUrl(ProjectImage $image): string
    {
        $productionUrl = config('services.meta.production_url', 'https://gs.construction');
        $project = $image->project;

        if ($project && $image->slug) {
            return "{$productionUrl}/projects/{$project->slug}/photos/{$image->slug}";
        }

        if ($project) {
            return "{$productionUrl}/projects/{$project->slug}";
        }

        return $productionUrl;
    }

    /* ------------------------------------------------------------------ */
    /*  Instagram helpers                                                  */
    /* ------------------------------------------------------------------ */

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
}
