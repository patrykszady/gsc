<?php

namespace App\Console\Commands;

use App\Jobs\PublishToSocialMediaJob;
use App\Models\ProjectImage;
use App\Models\ImageSocialPost;
use App\Services\MetaSocialService;
use Illuminate\Console\Command;

class PublishSocialMediaPost extends Command
{
    protected $signature = 'social:post
        {--platform=all : Platform to post to (instagram, facebook, google_business, all)}
        {--image= : Specific ProjectImage ID to post (otherwise picks random unposted)}
        {--dry-run : Show what would be posted without actually posting}
        {--instagram-container-only : Create Instagram media container only (uploads to Meta but does not publish publicly)}
        {--queue : Dispatch as a queued job instead of running synchronously}
        {--yes : Skip the interactive preview confirmation}
        {--random-delay=0 : Max random delay in minutes before posting (for natural scheduling)}
        {--via= : Publishing transport for Instagram (graph|puppeteer). Default: graph}';

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

        // Pick or find the image (with recycling fallback)
        $image = $this->resolveImage($platforms);
        $recycled = $image && $this->wasRecycled;

        if (! $image) {
            $this->warn('No images available for posting (none published or none with alt_text).');
            return 1;
        }

        if ($recycled) {
            $this->line('♻️  All images already posted — recycling least-recently-posted image.');
        }

        $project = $image->project;
        $shortLinkUrl = $service->getShortLinkUrl($image);
        $linkUrl = $service->getProjectPageUrl($image);
        $imageUrl = $service->getPublicImageUrl($image);

        $this->info("Selected image: #{$image->id} — {$image->alt_text}");
        $this->line("  Project: {$project->title} ({$project->project_type})");
        $this->line("  Location: {$project->location}");
        $this->line("  Link: {$linkUrl}");
        $this->line("  Short link: {$shortLinkUrl}");
        $this->line("  Image URL: {$imageUrl}");
        $this->line("  Platforms: " . implode(', ', $platforms));

        if ($this->option('instagram-container-only')) {
            if (! in_array('instagram', $platforms, true)) {
                $this->error('Instagram is not configured. Connect Meta via /admin/platforms first.');
                return 1;
            }

            $this->newLine();
            $this->warn('Instagram container-only mode — media will be sent to Meta but NOT published publicly.');

            $aiService = app(\App\Services\AiContentService::class);
            $content = $aiService->generateSocialMediaContent($image, $shortLinkUrl);

            if (! $content) {
                $this->error('AI content generation failed: ' . $aiService->getLastError());
                return 1;
            }

            $fullCaption = $this->buildFullCaption($content, $shortLinkUrl);
            $container = $service->createInstagramContainer($imageUrl, $fullCaption);

            if (! $container) {
                $error = $service->getLastError();
                $this->error('Container creation failed: ' . ($error['message'] ?? 'Unknown error'));
                if (is_array($error)) {
                    $this->line(json_encode($error, JSON_PRETTY_PRINT));
                }
                return 1;
            }

            $this->info('✅ Instagram container created (not published).');
            $this->line('Container ID: ' . $container['id']);
            $this->line('Note: Unpublished containers expire automatically on Meta side.');
            return 0;
        }

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
            $delay = $this->getRandomDelay();
            $job = PublishToSocialMediaJob::dispatch($image, $platforms)->onQueue('social-media');

            if ($delay > 0) {
                $job->delay(now()->addMinutes($delay));
                $this->info("📤 Job dispatched to queue with {$delay}-minute delay (posts ~" . now()->addMinutes($delay)->format('g:i A') . ').');
            } else {
                $this->info('📤 Job dispatched to queue.');
            }

            return 0;
        }

        // Run synchronously
        if (! $this->confirmPreview($image, $platforms, $service, $linkUrl, $shortLinkUrl, $imageUrl)) {
            $this->warn('Aborted by user.');
            return 1;
        }

        // Honour --random-delay on the synchronous path too — sleep up to N
        // minutes before actually posting, so scheduled runs land at random
        // times within the configured window.
        if (($delay = $this->getRandomDelay()) > 0) {
            $this->info("⏳ Sleeping {$delay} minute(s) before publishing (random delay)...");
            sleep($delay * 60);
        }

        // Puppeteer transport (Instagram only) — bypass Graph API to tag location.
        if ($this->option('via') === 'puppeteer') {
            if ($platforms !== ['instagram']) {
                $this->error('--via=puppeteer is only supported with --platform=instagram.');
                return 1;
            }

            return $this->publishInstagramViaPuppeteer($image, $shortLinkUrl);
        }

        $this->info('Publishing...');
        $job = new PublishToSocialMediaJob($image, $platforms);
        $job->handle(
            app(MetaSocialService::class),
            app(\App\Services\AiContentService::class),
        );

        // Show results
        $this->newLine();
        $posts = ImageSocialPost::where('project_image_id', $image->id)
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

    /** True when resolveImage() returned a recycled image (already posted before). */
    protected bool $wasRecycled = false;

    protected function resolveImage(array $platforms): ?ProjectImage
    {
        $this->wasRecycled = false;
        $imageId = $this->option('image');

        if ($imageId) {
            return ProjectImage::find($imageId);
        }

        $baseQuery = fn () => ProjectImage::query()
            ->whereHas('project', fn ($q) => $q->where('is_published', true))
            ->whereNotNull('alt_text')
            ->where('alt_text', '!=', '');

        // 1) Prefer images never posted to ANY requested platform.
        $query = $baseQuery();
        foreach ($platforms as $platform) {
            $query->whereDoesntHave('imageSocialPosts', function ($q) use ($platform) {
                $q->where('platform', $platform)
                    ->whereIn('status', ['published', 'pending']);
            });

            if ($platform === 'facebook') {
                $query->whereDoesntHave('imageSocialPosts', function ($q) {
                    $q->where('platform', 'instagram')
                        ->whereIn('status', ['published', 'pending']);
                });
            }
        }

        if ($image = $query->inRandomOrder()->first()) {
            return $image;
        }

        // 2) Recycle: pick the image whose last post on these platforms is OLDEST
        //    (or never posted there). Skip images with a pending post to avoid races.
        $this->wasRecycled = true;

        $recycleQuery = $baseQuery();
        foreach ($platforms as $platform) {
            $recycleQuery->whereDoesntHave('imageSocialPosts', function ($q) use ($platform) {
                $q->where('platform', $platform)->where('status', 'pending');
            });

            if ($platform === 'facebook') {
                $recycleQuery->whereDoesntHave('imageSocialPosts', function ($q) {
                    $q->where('platform', 'instagram')
                        ->whereIn('status', ['published', 'pending']);
                });
            }
        }

        // Order by the max published_at across requested platforms (oldest first).
        // NULL (never posted on this platform) sorts oldest, which is what we want.
        $platformList = collect($platforms)->map(fn ($p) => "'" . addslashes($p) . "'")->implode(',');
        $recycleQuery->leftJoinSub(
            \App\Models\ImageSocialPost::query()
                ->selectRaw('project_image_id, MAX(published_at) as last_published_at')
                ->whereIn('platform', $platforms)
                ->where('status', 'published')
                ->groupBy('project_image_id'),
            'last_posts',
            'last_posts.project_image_id',
            'project_images.id'
        )
            ->orderByRaw('last_posts.last_published_at IS NULL DESC')
            ->orderBy('last_posts.last_published_at', 'asc')
            ->select('project_images.*');

        return $recycleQuery->first();
    }

    /**
     * Calculate a random delay in minutes based on --random-delay option.
     */
    protected function getRandomDelay(): int
    {
        $maxDelay = (int) $this->option('random-delay');

        if ($maxDelay <= 0) {
            return 0;
        }

        return random_int(0, $maxDelay);
    }

    /**
     * Build the same full caption format used for live posting.
     */
    protected function buildFullCaption(array $content, string $shortLinkUrl): string
    {
        $parts = [];

        if (! empty($content['caption'])) {
            $parts[] = $content['caption'];
        }

        if ($shortLinkUrl !== '') {
            $parts[] = "\n🔗 {$shortLinkUrl}";
        }

        if (! empty($content['hashtags'])) {
            $parts[] = "\n{$content['hashtags']}";
        }

        return implode("\n", $parts);
    }

    /**
     * Show the full post preview (caption, hashtags, location, image URL) for
     * every platform and require confirmation before publishing live.
     */
    protected function confirmPreview(
        ProjectImage $image,
        array $platforms,
        MetaSocialService $metaService,
        string $linkUrl,
        string $shortLinkUrl,
        ?string $imageUrl,
    ): bool {
        $aiService = app(\App\Services\AiContentService::class);
        $content = $aiService->generateSocialMediaContent($image, $shortLinkUrl);

        if (! $content) {
            $this->error('AI content generation failed: ' . $aiService->getLastError());
            return false;
        }

        $locationId = $metaService->findInstagramLocationId($image->project?->location);

        foreach ($platforms as $platform) {
            $this->newLine();
            $this->line('────────────────────────────────────────');
            $this->info(strtoupper($platform) . ' PREVIEW');
            $this->line('────────────────────────────────────────');

            $this->line('<options=bold>Image:</> ' . ($imageUrl ?? 'n/a'));

            if ($platform === 'instagram') {
                if ($locationId) {
                    $this->line("<options=bold>Location tag:</> {$image->project?->location} (id={$locationId})");
                } else {
                    $this->line("<options=bold>Location tag:</> <comment>NONE</comment> ({$image->project?->location} — no valid IG Graph API location_id; requires Page Public Metadata Access App Review)");
                }
            }

            $previewPost = new ImageSocialPost([
                'platform' => $platform,
                'caption' => $content['caption'],
                'hashtags' => $content['hashtags'],
                'link_url' => $platform === 'instagram' ? $shortLinkUrl : $linkUrl,
            ]);

            $this->newLine();
            $this->line('<options=bold>Caption (as posted):</>');
            $this->line($previewPost->full_caption);
        }

        $this->newLine();
        $this->line('────────────────────────────────────────');

        if ($this->option('yes')) {
            $this->info('--yes flag set, skipping confirmation.');
            return true;
        }

        return $this->confirm('Publish this post LIVE?', false);
    }

    /**
     * Publish to Instagram via the Graph API (no location), then drive
     * instagram.com via Puppeteer to Edit the post and add a location tag.
     * The Graph API forbids location tagging without App Review, so this
     * hybrid flow is the most reliable way to ship a tagged post.
     */
    protected function publishInstagramViaPuppeteer(ProjectImage $image, string $shortLinkUrl): int
    {
        // 1. Run the normal Graph API publish job (creates the ImageSocialPost,
        //    uploads the image, returns a permalink).
        $this->info('Publishing via Graph API...');
        $job = new PublishToSocialMediaJob($image, ['instagram']);
        $job->handle(
            app(MetaSocialService::class),
            app(\App\Services\AiContentService::class),
        );

        $post = ImageSocialPost::where('project_image_id', $image->id)
            ->where('platform', 'instagram')
            ->latest('id')
            ->first();

        if (! $post || $post->status !== 'published' || ! $post->platform_permalink) {
            $err = $post?->error_message ?: 'unknown error';
            $this->error('❌ Graph API publish failed: ' . $err);
            return 1;
        }

        $this->info('✅ Published to Instagram (no location yet).');
        $this->line('   → ' . $post->platform_permalink);

        // 2. Resolve the location query for this image.
        $locationQuery = $image->project?->location ?: null;
        if (! $locationQuery) {
            $this->warn('No project location — skipping location tag.');
            return 0;
        }

        $userDataDir = env('INSTAGRAM_PUPPETEER_USER_DATA_DIR', storage_path('app/instagram-puppeteer'));
        if (! is_dir($userDataDir)) {
            $this->warn("Instagram session not found at {$userDataDir} — skipping location tag.");
            $this->line("Log in: node scripts/instagram-login.mjs --user-data-dir={$userDataDir}");
            return 0;
        }

        // 3. Drive instagram.com to Edit the post and add the location.
        $this->info("Adding location \"{$locationQuery}\" via Puppeteer...");

        $screenshotDir = storage_path('app/instagram-puppeteer/screenshots');
        $process = new \Symfony\Component\Process\Process([
            'node',
            base_path('scripts/instagram-add-location.mjs'),
            '--user-data-dir=' . $userDataDir,
            '--screenshot-dir=' . $screenshotDir,
            '--debug',
        ]);
        $process->setTimeout(300);
        $process->setInput(json_encode([
            'permalink' => $post->platform_permalink,
            'locationQuery' => $locationQuery,
        ]));

        $stdout = '';
        $process->run(function ($type, $buffer) use (&$stdout) {
            if ($type === \Symfony\Component\Process\Process::OUT) {
                $stdout .= $buffer;
            } else {
                $this->getOutput()->write("<comment>{$buffer}</comment>");
            }
        });

        $lastLine = collect(preg_split('/\r?\n/', trim($stdout)))->filter()->last();
        $result = $lastLine ? json_decode($lastLine, true) : null;

        if (! is_array($result) || empty($result['ok'])) {
            $err = $result['error'] ?? ('puppeteer script failed: ' . $stdout);
            $this->warn('⚠️  Location tag failed (post is still published): ' . $err);
            if (! empty($result['screenshot'])) {
                $this->line('   Screenshot: ' . $result['screenshot']);
            }
            return 0;
        }

        if (! empty($result['locationSelected'])) {
            $this->info('✅ Location tagged: ' . ($result['matchedLabel'] ?? $locationQuery));
        } else {
            $this->warn('⚠️  Location query had no matching suggestion — post saved without tag.');
        }

        return 0;
    }
}
