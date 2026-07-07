<?php

namespace App\Jobs;

use App\Models\ProjectImage;
use App\Models\ImageSocialPost;
use App\Services\AiContentService;
use App\Services\GoogleBusinessProfileService;
use App\Services\MetaSocialService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PublishToSocialMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900]; // 1, 5, 15 min
    /** @var array<string, mixed> */
    protected array $platformErrors = [];

    public function __construct(
        public ProjectImage $image,
        /** @var string[] */
        public array $platforms = ['instagram', 'facebook', 'google_business'],
    ) {}

    public function handle(MetaSocialService $metaService, AiContentService $aiService): void
    {
        $image = $this->image;
        $project = $image->project;

        if (! $project || ! $project->is_published) {
            Log::warning('Social Media: Skipping unpublished project image', ['image_id' => $image->id]);
            return;
        }

        $linkUrl = $metaService->getProjectPageUrl($image);
        $shortLinkUrl = $metaService->getShortLinkUrl($image);
        $imageUrl = $metaService->getPublicImageUrl($image);

        if (! $imageUrl) {
            Log::error('Social Media: No public URL for image', ['image_id' => $image->id]);
            return;
        }

        // Generate AI caption + hashtags (use short link in the prompt so AI sees it)
        $content = $aiService->generateSocialMediaContent($image, $shortLinkUrl);

        if (! $content) {
            Log::error('Social Media: AI content generation failed', [
                'image_id' => $image->id,
                'error' => $aiService->getLastError(),
            ]);
            return;
        }

        foreach ($this->platforms as $platform) {
            // Use short links for Instagram; full URL for other platforms
            $platformLinkUrl = ($platform === 'instagram') ? $shortLinkUrl : $linkUrl;
            $this->publishToPlatform($platform, $image, $metaService, $imageUrl, $content, $platformLinkUrl);
        }
    }

    /**
     * Get a public image URL for GBP (reuses GBP service's production URL logic).
     */
    protected function getGbpImageUrl(ProjectImage $image): ?string
    {
        $productionUrl = config('services.google.business_profile.production_url', 'https://gs.construction');
        $thumbnails = $image->thumbnails ?? [];
        // GBP posts render in a 4:3 frame — prefer the 4:3 'gbp' crop so the
        // image fills edge-to-edge instead of being letterboxed. Fall back to
        // the 16:9 'large' (or original) for images not yet backfilled.
        $path = $thumbnails['gbp'] ?? $thumbnails['large'] ?? $thumbnails['hero'] ?? $image->path;
        $relativePath = 'storage/' . ltrim($path, '/');

        return rtrim($productionUrl, '/') . '/' . $relativePath;
    }

    protected function publishToPlatform(
        string $platform,
        ProjectImage $image,
        MetaSocialService $metaService,
        string $imageUrl,
        array $content,
        string $linkUrl,
    ): void {
        $this->platformErrors[$platform] = null;

        // Block only if there's a pending post (race protection) or a published
        // post within the last 30 days (anti-spam). Older published posts are
        // eligible for recycling so weekly GBP posts keep flowing.
        $exists = ImageSocialPost::where('project_image_id', $image->id)
            ->where('platform', $platform)
            ->where(function ($q) {
                $q->where('status', 'pending')
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'published')
                            ->where('published_at', '>=', now()->subDays(30));
                    });
            })
            ->exists();

        if ($exists) {
            Log::info("Social Media: Skipping {$platform} (recent or pending post exists)", ['image_id' => $image->id]);
            return;
        }

        // Create the tracking record
        [$caption, $hashtags] = $this->formatContentForPlatform($platform, $content);

        $post = ImageSocialPost::create([
            'project_image_id' => $image->id,
            'platform' => $platform,
            'status' => 'pending',
            'caption' => $caption,
            'hashtags' => $hashtags,
            'link_url' => $linkUrl,
        ]);

        // Build the full caption (caption + link + hashtags)
        $fullCaption = $post->full_caption;

        try {
            $result = match ($platform) {
                'instagram' => $metaService->publishToInstagramForImage($image, $fullCaption),
                'facebook' => $metaService->publishToFacebook(
                    $imageUrl,
                    $fullCaption,
                    $metaService->findFacebookPlaceId($image->project?->location)
                        ?: config('services.meta.facebook_place_id')
                ),
                'google_business' => $this->publishToGoogleBusiness($image, $content, $linkUrl),
                default => null,
            };

            if ($result) {
                $post->markPublished($result['id'], $result['permalink'] ?? null);
                Log::info("Social Media: Published to {$platform}", [
                    'image_id' => $image->id,
                    'post_id' => $result['id'],
                ]);
            } else {
                $error = $platform === 'google_business'
                    ? ($this->platformErrors['google_business'] ?? null)
                    : $metaService->getLastError();

                $post->markFailed($this->formatErrorForStorage($error));
                Log::error("Social Media: Failed to publish to {$platform}", [
                    'image_id' => $image->id,
                    'error' => $error,
                ]);
            }
        } catch (\Throwable $e) {
            $post->markFailed($e->getMessage());
            Log::error("Social Media: Exception publishing to {$platform}", [
                'image_id' => $image->id,
                'exception' => $e->getMessage(),
            ]);
            throw $e; // Re-throw so the job retries
        }
    }

    /**
     * Publish a Local Post ("Update") to Google Business Profile.
     */
    protected function publishToGoogleBusiness(ProjectImage $image, array $content, string $linkUrl): ?array
    {
        $gbpService = app(GoogleBusinessProfileService::class);

        if (! $gbpService->isConfigured()) {
            return null;
        }

        $gbpImageUrl = $this->getGbpImageUrl($image);
        if (! $gbpImageUrl) {
            return null;
        }

        // GBP posts don't support hashtags — use just the caption
        $summary = $content['caption'];

        $result = $gbpService->createLocalPost($gbpImageUrl, $summary, $linkUrl, 'LEARN_MORE');

        if (! $result) {
            $this->platformErrors['google_business'] = $gbpService->getLastError();
            return null;
        }

        return [
            'id' => $result['name'],
            'permalink' => $result['searchUrl'],
        ];
    }

    /**
     * Convert mixed service error payloads into a readable, compact string.
     */
    protected function formatErrorForStorage(mixed $error): string
    {
        if (is_string($error) && trim($error) !== '') {
            return $error;
        }

        if (is_array($error)) {
            $message = $error['message'] ?? $error['error_description'] ?? null;
            $status = $error['status'] ?? null;

            if (is_string($message) && trim($message) !== '') {
                return $status ? "{$message} (status {$status})" : $message;
            }

            $encoded = json_encode($error, JSON_UNESCAPED_SLASHES);
            if (is_string($encoded) && $encoded !== 'null' && $encoded !== '') {
                return $encoded;
            }
        }

        return 'Unknown publish error';
    }

    /**
     * Apply platform-specific text shaping before persistence/publish.
     * Facebook performs better with fewer hashtags and concise copy.
     *
     * @param array{caption:string,hashtags:string} $content
     * @return array{0:string,1:string}
     */
    protected function formatContentForPlatform(string $platform, array $content): array
    {
        $caption = trim((string) ($content['caption'] ?? ''));
        $hashtags = trim((string) ($content['hashtags'] ?? ''));

        if ($platform !== 'facebook') {
            return [$caption, $hashtags];
        }

        // Facebook benefits from fuller storytelling than Instagram.
        // If AI returns a short caption, add one extra value/CTA line.
        if (mb_strlen($caption) < 260) {
            $caption .= "\n\nThinking about a remodel like this? See more project photos on our site and request your estimate.";
        }

        // Keep within FB-friendly length while allowing richer copy.
        $caption = trim(Str::limit($caption, 1200, ''));

        preg_match_all('/#[A-Za-z0-9_]+/u', $hashtags, $matches);
        $tags = collect($matches[0] ?? [])
            ->map(fn ($t) => trim($t))
            ->filter()
            ->unique(fn ($t) => mb_strtolower($t))
            ->take(8)
            ->values()
            ->all();

        return [$caption, implode(' ', $tags)];
    }
}
