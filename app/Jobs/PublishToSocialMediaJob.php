<?php

namespace App\Jobs;

use App\Models\ProjectImage;
use App\Models\SocialMediaPost;
use App\Services\AiContentService;
use App\Services\GoogleBusinessProfileService;
use App\Services\MetaSocialService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishToSocialMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900]; // 1, 5, 15 min

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
        $imageUrl = $metaService->getPublicImageUrl($image);

        if (! $imageUrl) {
            Log::error('Social Media: No public URL for image', ['image_id' => $image->id]);
            return;
        }

        // Generate AI caption + hashtags
        $content = $aiService->generateSocialMediaContent($image, $linkUrl);

        if (! $content) {
            Log::error('Social Media: AI content generation failed', [
                'image_id' => $image->id,
                'error' => $aiService->getLastError(),
            ]);
            return;
        }

        foreach ($this->platforms as $platform) {
            $this->publishToPlatform($platform, $image, $metaService, $imageUrl, $content, $linkUrl);
        }
    }

    /**
     * Get a public image URL for GBP (reuses GBP service's production URL logic).
     */
    protected function getGbpImageUrl(ProjectImage $image): ?string
    {
        $productionUrl = config('services.google.business_profile.production_url', 'https://gs.construction');
        $thumbnails = $image->thumbnails ?? [];
        $path = $thumbnails['large'] ?? $thumbnails['hero'] ?? $image->path;
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
        // Check if already posted to this platform
        $exists = SocialMediaPost::where('project_image_id', $image->id)
            ->where('platform', $platform)
            ->whereIn('status', ['published', 'pending'])
            ->exists();

        if ($exists) {
            Log::info("Social Media: Already posted to {$platform}", ['image_id' => $image->id]);
            return;
        }

        // Create the tracking record
        $post = SocialMediaPost::create([
            'project_image_id' => $image->id,
            'platform' => $platform,
            'status' => 'pending',
            'caption' => $content['caption'],
            'hashtags' => $content['hashtags'],
            'link_url' => $linkUrl,
        ]);

        // Build the full caption (caption + link + hashtags)
        $fullCaption = $post->full_caption;

        try {
            $result = match ($platform) {
                'instagram' => $metaService->publishToInstagram($imageUrl, $fullCaption),
                'facebook' => $metaService->publishToFacebook($imageUrl, $fullCaption),
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
                $error = $metaService->getLastError();
                $post->markFailed(json_encode($error));
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
            return null;
        }

        return [
            'id' => $result['name'],
            'permalink' => $result['searchUrl'],
        ];
    }
}
