<?php

namespace App\Livewire\Admin;

use App\Jobs\PublishToSocialMediaJob;
use App\Models\ProjectImage;
use App\Models\ImageSocialPost;
use App\Models\PlatformSetting;
use App\Services\GoogleBusinessProfileService;
use App\Services\MetaSocialService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.admin')]
#[Title('Social Media')]
class SocialMediaPosts extends Component
{
    private const SOCIAL_PLATFORMS = ['instagram', 'google', 'facebook', 'yelp', 'houzz', 'angi'];

    public string $platformFilter = '';
    public string $statusFilter = '';
    public int $remainingInstagram = 0;
    public int $postedInstagram = 0;
    public int $remainingFacebook = 0;
    public int $postedFacebook = 0;
    public int $remainingGbp = 0;
    public int $remainingYelp = 0;
    public int $uploadedYelp = 0;
    public int $uploadedGbp = 0;
    public int $postedGbp = 0;
    public array $socialUrls = [];

    public function mount(): void
    {
        $this->loadSocialUrls();

        $this->remainingInstagram = ImageSocialPost::unpostedImagesQuery('instagram')->count();
        $this->postedInstagram = ImageSocialPost::where('platform', 'instagram')
            ->where('status', 'published')
            ->count();
        $this->remainingFacebook = ImageSocialPost::unpostedImagesQuery('facebook')->count();
        $this->postedFacebook = ImageSocialPost::where('platform', 'facebook')
            ->where('status', 'published')
            ->count();
        $this->remainingGbp = ProjectImage::whereHas('project', fn ($q) => $q->where('is_published', true))
            ->notUploadedTo('google_places')->count();
        $this->uploadedGbp = ProjectImage::whereHas('project', fn ($q) => $q->where('is_published', true))
            ->uploadedTo('google_places')->count();
        $this->postedGbp = ImageSocialPost::where('platform', 'google_business')
            ->where('status', 'published')
            ->count();
        $this->remainingYelp = ProjectImage::whereHas('project', fn ($q) => $q->where('is_published', true))
            ->notUploadedTo('yelp_biz')->count();
        $this->uploadedYelp = ProjectImage::uploadedTo('yelp_biz')->count();
    }

    public function saveSocialUrls(): void
    {
        $validated = $this->validate([
            'socialUrls.instagram' => 'nullable|url|max:500',
            'socialUrls.google' => 'nullable|url|max:500',
            'socialUrls.facebook' => 'nullable|url|max:500',
            'socialUrls.yelp' => 'nullable|url|max:500',
            'socialUrls.houzz' => 'nullable|url|max:500',
            'socialUrls.angi' => 'nullable|url|max:500',
        ]);

        foreach (self::SOCIAL_PLATFORMS as $platform) {
            $url = trim((string) ($validated['socialUrls'][$platform] ?? ''));
            PlatformSetting::put('socials.url.' . $platform, $url !== '' ? $url : null);
            config()->set('socials.' . $platform . '.url', $url !== '' ? $url : $this->defaultSocialUrl($platform));
        }

        session()->flash('success', 'Social profile URLs saved.');
        $this->loadSocialUrls();
    }

    private function loadSocialUrls(): void
    {
        $urls = [];
        foreach (self::SOCIAL_PLATFORMS as $platform) {
            $default = $this->defaultSocialUrl($platform);
            $urls[$platform] = (string) PlatformSetting::get('socials.url.' . $platform, $default);
        }
        $this->socialUrls = $urls;
    }

    private function defaultSocialUrl(string $platform): string
    {
        static $defaults = null;

        if (! is_array($defaults)) {
            $defaults = require config_path('socials.php');
        }

        return (string) ($defaults[$platform]['url'] ?? '');
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
        $image = ImageSocialPost::pickRandomUnposted($platforms[0]);

        if (! $image) {
            session()->flash('info', 'All images have been posted! No unposted images remaining.');
            return;
        }

        PublishToSocialMediaJob::dispatch($image, $platforms)->onQueue('social-media');
        session()->flash('success', "Queued post for \"{$image->project->title}\" image #{$image->id}.");
    }

    public function render()
    {
        $query = ImageSocialPost::with('projectImage.project')
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

        $remainingImages = collect();
        $remainingPlatformLabels = [
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
        ];

        foreach ($remainingPlatformLabels as $platform => $label) {
            if ($this->platformFilter && $this->platformFilter !== $platform) {
                continue;
            }

            $remainingImages = $remainingImages->merge(
                ImageSocialPost::unpostedImagesQuery($platform)
                    ->with('project')
                    ->latest('id')
                    ->get()
                    ->map(fn (ProjectImage $image) => [
                        'kind' => 'remaining',
                        'platform' => $platform,
                        'label' => $label,
                        'image' => $image,
                    ])
            );
        }

        if (! $this->platformFilter || $this->platformFilter === 'google_business') {
            $remainingImages = $remainingImages->merge(
                ProjectImage::with('project')
                    ->whereHas('project', fn ($q) => $q->where('is_published', true))
                    ->notUploadedTo('google_places')
                    ->latest('id')
                    ->get()
                    ->map(fn (ProjectImage $image) => [
                        'kind' => 'remaining',
                        'platform' => 'google_business',
                        'label' => 'Google Business',
                        'image' => $image,
                    ])
            );
        }

        $totalEligible = ProjectImage::whereHas('project', fn ($q) => $q->where('is_published', true))
            ->whereNotNull('alt_text')
            ->where('alt_text', '!=', '')
            ->count();

        $gbpImages = ProjectImage::with('project')
            ->whereHas('project', fn ($q) => $q->where('is_published', true))
            ->uploadedTo('google_places')
            ->orderByUploadedTo('google_places');

        $yelpImages = ProjectImage::with('project')
            ->uploadedTo('yelp_biz')
            ->orderByUploadedTo('yelp_biz');

        return view('livewire.admin.social-media-posts', [
            'uploadedPosts' => $this->platformFilter === 'yelp' ? collect() : $query->get(),
            'remainingImages' => $remainingImages,
            'gbpImages' => $gbpImages->get(),
            'yelpImages' => $this->platformFilter === 'yelp' || $this->platformFilter === ''
                ? $yelpImages->get()
                : null,
            'isConfigured' => $isConfigured,
            'totalEligible' => $totalEligible,
        ]);
    }
}
