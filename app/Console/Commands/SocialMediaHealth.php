<?php

namespace App\Console\Commands;

use App\Models\SocialMediaPost;
use App\Models\ProjectImage;
use App\Services\GoogleBusinessProfileService;
use App\Services\MetaSocialService;
use Illuminate\Console\Command;

class SocialMediaHealth extends Command
{
    protected $signature = 'social:health';

    protected $description = 'Show social media posting status, stats, and remaining unposted images';

    public function handle(MetaSocialService $service): int
    {
        $gbpService = app(GoogleBusinessProfileService::class);

        $this->info('=== Social Media Health ===');
        $this->newLine();

        // Configuration status
        $this->table(['Platform', 'Configured'], [
            ['Instagram', $service->isInstagramConfigured() ? '✅ Yes' : '❌ No'],
            ['Facebook', $service->isFacebookConfigured() ? '✅ Yes' : '❌ No'],
            ['Google Business', $gbpService->isConfigured() ? '✅ Yes' : '❌ No'],
        ]);

        // Posting stats
        $totalImages = ProjectImage::whereHas('project', fn ($q) => $q->where('is_published', true))
            ->whereNotNull('alt_text')
            ->where('alt_text', '!=', '')
            ->count();

        $igPosted = SocialMediaPost::where('platform', 'instagram')->published()->count();
        $fbPosted = SocialMediaPost::where('platform', 'facebook')->published()->count();
        $gbpPosted = SocialMediaPost::where('platform', 'google_business')->published()->count();
        $igFailed = SocialMediaPost::where('platform', 'instagram')->failed()->count();
        $fbFailed = SocialMediaPost::where('platform', 'facebook')->failed()->count();
        $gbpFailed = SocialMediaPost::where('platform', 'google_business')->failed()->count();

        $igRemaining = SocialMediaPost::unpostedImagesQuery('instagram')->count();
        $fbRemaining = SocialMediaPost::unpostedImagesQuery('facebook')->count();
        $gbpRemaining = SocialMediaPost::unpostedImagesQuery('google_business')->count();

        $this->newLine();
        $this->table(['Metric', 'Instagram', 'Facebook', 'Google Business'], [
            ['Total eligible images', $totalImages, $totalImages, $totalImages],
            ['Posted ✅', $igPosted, $fbPosted, $gbpPosted],
            ['Failed ❌', $igFailed, $fbFailed, $gbpFailed],
            ['Remaining', $igRemaining, $fbRemaining, $gbpRemaining],
            ['Days of content left', $igRemaining . ' days', $fbRemaining . ' days', $gbpRemaining . ' days'],
        ]);

        // Last posts
        $lastPosts = SocialMediaPost::with('projectImage.project')
            ->published()
            ->latest('published_at')
            ->take(5)
            ->get();

        if ($lastPosts->isNotEmpty()) {
            $this->newLine();
            $this->info('Last 5 posts:');
            $rows = $lastPosts->map(fn ($p) => [
                $p->platform,
                $p->published_at?->format('Y-m-d H:i'),
                $p->projectImage?->project?->title ?? 'N/A',
                mb_substr($p->caption ?? '', 0, 50) . '...',
            ]);
            $this->table(['Platform', 'Published', 'Project', 'Caption'], $rows->toArray());
        }

        return 0;
    }
}
