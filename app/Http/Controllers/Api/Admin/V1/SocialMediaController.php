<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Jobs\PublishToSocialMediaJob;
use App\Models\ImageSocialPost;
use App\Models\PlatformSetting;
use App\Models\ProjectImage;
use App\Services\GoogleBusinessProfileService;
use App\Services\MetaSocialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Management API for gsc's Livewire\Admin\SocialMediaPosts screen.
 *
 * One aggregate index() call carries everything the original component's
 * render() gathered in a single pass (stats, config flags, the URL roster,
 * and the four image/post lists) — the source never paginated any of these
 * (all plain ->get() calls against a small per-tenant table), so this
 * mirrors that rather than inventing pagination the screen never had.
 *
 * postNow()'s job dispatch (post()) is faithfully ported but must never be
 * exercised against a live app outside phpunit + Queue::fake/Http::fake —
 * it can publish to real Meta/Instagram/GBP accounts.
 */
class SocialMediaController extends Controller
{
    private const PLATFORM_LABELS = [
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'google_business' => 'Google Business',
    ];

    public function index(Request $request): JsonResponse
    {
        $platformFilter = $request->string('platform')->toString();
        $statusFilter = $request->string('status')->toString();

        $metaService = app(MetaSocialService::class);
        $gbpService = app(GoogleBusinessProfileService::class);

        $igConfigured = $metaService->isInstagramConfigured();
        $fbConfigured = $metaService->isFacebookConfigured();
        $gbpConfigured = $gbpService->isConfigured();

        $publishedProject = fn ($q) => $q->where('is_published', true);

        $stats = [
            'remaining_instagram' => ImageSocialPost::unpostedImagesQuery('instagram')->count(),
            'posted_instagram' => ImageSocialPost::where('platform', 'instagram')->where('status', 'published')->count(),
            'remaining_facebook' => ImageSocialPost::unpostedImagesQuery('facebook')->count(),
            'posted_facebook' => ImageSocialPost::where('platform', 'facebook')->where('status', 'published')->count(),
            'remaining_gbp' => ProjectImage::whereHas('project', $publishedProject)->notUploadedTo('google_places')->count(),
            'uploaded_gbp' => ProjectImage::whereHas('project', $publishedProject)->uploadedTo('google_places')->count(),
            'posted_gbp' => ImageSocialPost::where('platform', 'google_business')->where('status', 'published')->count(),
            'remaining_yelp' => ProjectImage::whereHas('project', $publishedProject)->notUploadedTo('yelp_biz')->count(),
            'uploaded_yelp' => ProjectImage::uploadedTo('yelp_biz')->count(),
            'total_eligible' => ProjectImage::whereHas('project', $publishedProject)
                ->whereNotNull('alt_text')->where('alt_text', '!=', '')->count(),
        ];

        $postsQuery = ImageSocialPost::with('projectImage.project')->latest('published_at')->latest('created_at');
        if ($platformFilter) {
            $postsQuery->where('platform', $platformFilter);
        }
        if ($statusFilter) {
            $postsQuery->where('status', $statusFilter);
        }
        $uploadedPosts = $platformFilter === 'yelp' ? collect() : $postsQuery->get();

        $remainingImages = collect();
        $remainingPlatformLabels = ['instagram' => 'Instagram', 'facebook' => 'Facebook'];
        foreach ($remainingPlatformLabels as $platform => $label) {
            if ($platformFilter && $platformFilter !== $platform) {
                continue;
            }

            $remainingImages = $remainingImages->merge(
                ImageSocialPost::unpostedImagesQuery($platform)->with('project')->latest('id')->get()
                    ->map(fn (ProjectImage $image) => [
                        'kind' => 'remaining',
                        'platform' => $platform,
                        'label' => $label,
                        'image' => $image->toSocialApiArray(),
                    ])
            );
        }
        if (! $platformFilter || $platformFilter === 'google_business') {
            $remainingImages = $remainingImages->merge(
                ProjectImage::with('project')->whereHas('project', $publishedProject)
                    ->notUploadedTo('google_places')->latest('id')->get()
                    ->map(fn (ProjectImage $image) => [
                        'kind' => 'remaining',
                        'platform' => 'google_business',
                        'label' => 'Google Business',
                        'image' => $image->toSocialApiArray(),
                    ])
            );
        }

        $gbpImages = ProjectImage::with('project')->whereHas('project', $publishedProject)
            ->uploadedTo('google_places')->orderByUploadedTo('google_places')->get();

        // No is_published filter here — matches the source component exactly
        // (yelpImages, unlike gbpImages, is not scoped to published projects).
        $yelpImages = ($platformFilter === 'yelp' || $platformFilter === '')
            ? ProjectImage::with('project')->uploadedTo('yelp_biz')->orderByUploadedTo('yelp_biz')->get()
            : null;

        return response()->json([
            'data' => [
                'stats' => $stats,
                'configured' => [
                    'instagram' => $igConfigured,
                    'facebook' => $fbConfigured,
                    'google_business' => $gbpConfigured,
                    'any' => $igConfigured || $fbConfigured || $gbpConfigured,
                ],
                'platforms' => $this->platformsPayload(),
                'uploaded_posts' => $uploadedPosts->map(fn (ImageSocialPost $post) => $post->toApiArray())->values()->all(),
                'remaining_images' => $remainingImages->values()->all(),
                'gbp_images' => $gbpImages->map(fn (ProjectImage $image) => $image->toSocialApiArray())->values()->all(),
                'yelp_images' => $yelpImages?->map(fn (ProjectImage $image) => $image->toSocialApiArray())->values()->all(),
            ],
        ]);
    }

    public function saveUrls(Request $request): JsonResponse
    {
        $platforms = array_keys((array) config('social-platforms', []));

        $data = $request->validate(
            collect($platforms)->mapWithKeys(fn (string $p) => ["urls.{$p}" => 'nullable|url|max:500'])->all()
        );

        foreach ($platforms as $platform) {
            $url = trim((string) ($data['urls'][$platform] ?? ''));
            PlatformSetting::put('socials.url.'.$platform, $url !== '' ? $url : null);
        }

        return response()->json(['data' => ['platforms' => $this->platformsPayload()]]);
    }

    /**
     * Faithful port of SocialMediaPosts::postNow(). NEVER call against a
     * live app during verification — dispatches a real publish job.
     */
    public function post(Request $request): JsonResponse
    {
        $platform = $request->string('platform')->toString() ?: 'all';

        $metaService = app(MetaSocialService::class);
        $gbpService = app(GoogleBusinessProfileService::class);
        $platforms = [];

        if (in_array($platform, ['instagram', 'all'], true) && $metaService->isInstagramConfigured()) {
            $platforms[] = 'instagram';
        }
        if (in_array($platform, ['facebook', 'all'], true) && $metaService->isFacebookConfigured()) {
            $platforms[] = 'facebook';
        }
        if (in_array($platform, ['google_business', 'all'], true) && $gbpService->isConfigured()) {
            $platforms[] = 'google_business';
        }

        if (empty($platforms)) {
            $label = $platform === 'all'
                ? 'No platform is connected. Reconnect it under Platforms.'
                : (self::PLATFORM_LABELS[$platform] ?? $platform).' is not connected. Reconnect it under Platforms.';

            return response()->json(['message' => $label], 409);
        }

        $image = ImageSocialPost::pickRandomUnposted($platforms[0]);

        if (! $image) {
            return response()->json([
                'data' => [
                    'status' => 'no_images',
                    'message' => 'All images have been posted! No unposted images remaining.',
                ],
            ]);
        }

        PublishToSocialMediaJob::dispatch($image, $platforms)->onQueue('social-media');

        $names = implode(', ', array_map(fn ($p) => self::PLATFORM_LABELS[$p] ?? $p, $platforms));

        return response()->json([
            'data' => [
                'status' => 'queued',
                'message' => "Queued {$names} post for \"{$image->project->title}\" image #{$image->id}. It publishes via the social-media worker within a minute or two.",
            ],
        ]);
    }

    /**
     * Every platform the admin offers a field for (config/social-platforms.php),
     * merged with this tenant's saved URL — see SocialMediaPosts::loadSocialUrls().
     */
    protected function platformsPayload(): array
    {
        return collect(config('social-platforms', []))
            ->map(function (array $cat, string $key) {
                $icon = config("socials.{$key}.icon", $cat['icon'] ?? null);

                return [
                    'key' => $key,
                    'label' => config("socials.{$key}.label", $cat['label'] ?? ucfirst($key)),
                    'icon' => $icon ? asset($icon) : null,
                    'placeholder' => $cat['placeholder'] ?? 'https://…',
                    'url' => PlatformSetting::get('socials.url.'.$key, (string) config("socials.{$key}.url", '')),
                ];
            })
            ->values()
            ->all();
    }
}
