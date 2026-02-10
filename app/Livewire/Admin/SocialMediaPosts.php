<?php

namespace App\Livewire\Admin;

use App\Jobs\PublishToSocialMediaJob;
use App\Models\ProjectImage;
use App\Models\SocialMediaPost;
use App\Services\GoogleBusinessProfileService;
use App\Services\MetaSocialService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
#[Title('Social Media')]
class SocialMediaPosts extends Component
{
    use WithPagination;

    public string $platformFilter = '';
    public string $statusFilter = '';
    public int $remainingInstagram = 0;
    public int $remainingFacebook = 0;
    public int $remainingGbp = 0;

    public function mount(): void
    {
        $this->remainingInstagram = SocialMediaPost::unpostedImagesQuery('instagram')->count();
        $this->remainingFacebook = SocialMediaPost::unpostedImagesQuery('facebook')->count();
        $this->remainingGbp = SocialMediaPost::unpostedImagesQuery('google_business')->count();
    }

    public function postNow(string $platform = 'all'): void
    {
        $metaService = app(MetaSocialService::class);
        $gbpService = app(GoogleBusinessProfileService::class);
        $platforms = [];

        if (in_array($platform, ['instagram', 'all']) && $metaService->isInstagramConfigured()) {
            $platforms[] = 'instagram';
        }
        if (in_array($platform, ['facebook', 'all']) && $metaService->isFacebookConfigured()) {
            $platforms[] = 'facebook';
        }
        if (in_array($platform, ['google_business', 'all']) && $gbpService->isConfigured()) {
            $platforms[] = 'google_business';
        }

        if (empty($platforms)) {
            session()->flash('error', 'No platforms configured. Add META_* variables to .env first.');
            return;
        }

        // Pick a random unposted image
        $image = SocialMediaPost::pickRandomUnposted($platforms[0]);

        if (! $image) {
            session()->flash('info', 'All images have been posted! No unposted images remaining.');
            return;
        }

        PublishToSocialMediaJob::dispatch($image, $platforms)->onQueue('social-media');
        session()->flash('success', "Queued post for \"{$image->project->title}\" image #{$image->id}.");
    }

    public function render()
    {
        $query = SocialMediaPost::with('projectImage.project')
            ->latest('published_at')
            ->latest('created_at');

        if ($this->platformFilter) {
            $query->where('platform', $this->platformFilter);
        }
        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        $isConfigured = app(MetaSocialService::class)->isInstagramConfigured()
            || app(MetaSocialService::class)->isFacebookConfigured()
            || app(GoogleBusinessProfileService::class)->isConfigured();

        $totalEligible = ProjectImage::whereHas('project', fn ($q) => $q->where('is_published', true))
            ->whereNotNull('alt_text')
            ->where('alt_text', '!=', '')
            ->count();

        return view('livewire.admin.social-media-posts', [
            'posts' => $query->paginate(25),
            'isConfigured' => $isConfigured,
            'totalEligible' => $totalEligible,
            'publishedCount' => SocialMediaPost::published()->count(),
        ]);
    }
}
