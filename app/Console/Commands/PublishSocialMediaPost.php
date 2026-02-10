<?php

namespace App\Console\Commands;

use App\Jobs\PublishToSocialMediaJob;
use App\Models\ProjectImage;
use App\Models\SocialMediaPost;
use App\Services\MetaSocialService;
use Illuminate\Console\Command;

class PublishSocialMediaPost extends Command
{
    protected $signature = 'social:post
        {--platform=all : Platform to post to (instagram, facebook, google_business, all)}
        {--image= : Specific ProjectImage ID to post (otherwise picks random unposted)}
        {--dry-run : Show what would be posted without actually posting}
        {--queue : Dispatch as a queued job instead of running synchronously}';

    protected $description = 'Publish a random unposted project image to Instagram, Facebook, and/or Google Business Profile with AI-generated content';

    public function handle(MetaSocialService $service): int
    {
        $isDryRun = $this->option('dry-run');
        $platforms = $this->resolvePlatforms();

        if (empty($platforms) && ! $isDryRun) {
            $this->warn('No platforms configured. Check your .env for META_* variables.');
            return 1;
        }

        // For dry-run, default to all platforms even if not configured
        if ($isDryRun && empty($platforms)) {
            $platforms = ['instagram', 'facebook', 'google_business'];
        }

        // Pick or find the image
        $image = $this->resolveImage($platforms);

        if (! $image) {
            $this->info('🎉 All images have been posted! No unposted images remaining.');
            return 0;
        }

        $project = $image->project;
        $linkUrl = $service->getProjectPageUrl($image);
        $imageUrl = $service->getPublicImageUrl($image);

        $this->info("Selected image: #{$image->id} — {$image->alt_text}");
        $this->line("  Project: {$project->title} ({$project->project_type})");
        $this->line("  Location: {$project->location}");
        $this->line("  Link: {$linkUrl}");
        $this->line("  Image URL: {$imageUrl}");
        $this->line("  Platforms: " . implode(', ', $platforms));

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('Dry-run mode — generating AI content preview...');

            $aiService = app(\App\Services\AiContentService::class);
            $content = $aiService->generateSocialMediaContent($image, $linkUrl);

            if ($content) {
                $this->newLine();
                $this->info('📝 AI Caption:');
                $this->line($content['caption']);
                $this->newLine();
                $this->info('#️⃣  Hashtags:');
                $this->line($content['hashtags']);
            } else {
                $this->error('AI content generation failed: ' . $aiService->getLastError());
            }

            return 0;
        }

        if ($this->option('queue')) {
            PublishToSocialMediaJob::dispatch($image, $platforms)->onQueue('social-media');
            $this->info('📤 Job dispatched to queue.');
            return 0;
        }

        // Run synchronously
        $this->info('Publishing...');
        $job = new PublishToSocialMediaJob($image, $platforms);
        $job->handle(
            app(MetaSocialService::class),
            app(\App\Services\AiContentService::class),
        );

        // Show results
        $this->newLine();
        $posts = SocialMediaPost::where('project_image_id', $image->id)
            ->whereIn('platform', $platforms)
            ->latest()
            ->get();

        foreach ($posts as $post) {
            $status = $post->status === 'published' ? '✅' : '❌';
            $this->line("{$status} {$post->platform}: {$post->status}");
            if ($post->platform_permalink) {
                $this->line("   → {$post->platform_permalink}");
            }
            if ($post->error_message) {
                $this->error("   Error: {$post->error_message}");
            }
        }

        return $posts->contains('status', 'failed') ? 1 : 0;
    }

    protected function resolvePlatforms(): array
    {
        $metaService = app(MetaSocialService::class);
        $gbpService = app(\App\Services\GoogleBusinessProfileService::class);
        $requested = $this->option('platform');

        $platforms = [];
        if (in_array($requested, ['instagram', 'all']) && $metaService->isInstagramConfigured()) {
            $platforms[] = 'instagram';
        }
        if (in_array($requested, ['facebook', 'all']) && $metaService->isFacebookConfigured()) {
            $platforms[] = 'facebook';
        }
        if (in_array($requested, ['google_business', 'all']) && $gbpService->isConfigured()) {
            $platforms[] = 'google_business';
        }

        return $platforms;
    }

    protected function resolveImage(array $platforms): ?ProjectImage
    {
        $imageId = $this->option('image');

        if ($imageId) {
            return ProjectImage::find($imageId);
        }

        // Pick random image that hasn't been posted to ANY of the requested platforms
        // Prioritize images unposted to ALL platforms
        $query = ProjectImage::query()
            ->whereHas('project', fn ($q) => $q->where('is_published', true))
            ->whereNotNull('alt_text')
            ->where('alt_text', '!=', '');

        foreach ($platforms as $platform) {
            $query->whereDoesntHave('socialMediaPosts', function ($q) use ($platform) {
                $q->where('platform', $platform)
                    ->whereIn('status', ['published', 'pending']);
            });
        }

        return $query->inRandomOrder()->first();
    }
}
