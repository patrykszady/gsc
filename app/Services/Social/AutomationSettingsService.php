<?php

namespace App\Services\Social;

use App\Models\ImageSocialPost;
use App\Models\Site;
use App\Models\SocialAutomationSetting;
use App\Services\GoogleBusinessProfileService;
use App\Services\MetaSocialService;
use Illuminate\Support\Carbon;

/**
 * Builds the GET/PUT /api/admin/v1/social-media automation contract for the
 * current tenant (Site::current()) — one <item> per
 * App\Models\SocialAutomationSetting::PLATFORMS, in that fixed order.
 */
class AutomationSettingsService
{
    public function __construct(protected AutomationPlanner $planner) {}

    public function timezone(): string
    {
        return $this->planner->timezone();
    }

    /** @return array<int, array<string, mixed>> */
    public function items(): array
    {
        return collect(SocialAutomationSetting::PLATFORMS)
            ->map(fn (string $platform) => $this->item($platform))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function item(string $platform): array
    {
        $setting = SocialAutomationSetting::where('platform', $platform)->first();
        $defaults = SocialAutomationSetting::defaultsFor($platform);

        $cadence = $setting->cadence ?? $defaults['cadence'];
        $options = $setting->options ?? $defaults['options'];

        return [
            'platform' => $platform,
            'label' => SocialAutomationSetting::LABELS[$platform] ?? ucfirst($platform),
            'configured' => $this->isConfigured($platform),
            'enabled' => (bool) ($setting->enabled ?? false),
            'cadence' => $cadence,
            'options' => $options,
            'plan' => $this->planner->plan(Site::current()->slug, $platform, $cadence),
            'last' => $this->lastStats($platform),
            'updated_at' => optional($setting?->updated_at)->toIso8601String(),
        ];
    }

    /** Persist a validated {enabled, cadence, options} payload and return the refreshed item. */
    public function save(string $platform, bool $enabled, array $cadence, array $options): array
    {
        SocialAutomationSetting::updateOrCreate(
            ['platform' => $platform],
            ['enabled' => $enabled, 'cadence' => $cadence, 'options' => $options],
        );

        return $this->item($platform);
    }

    protected function isConfigured(string $platform): bool
    {
        return match ($platform) {
            'instagram' => app(MetaSocialService::class)->isInstagramConfigured(),
            'facebook' => app(MetaSocialService::class)->isFacebookConfigured(),
            'google_business' => app(GoogleBusinessProfileService::class)->isConfigured(),
            default => false,
        };
    }

    /** @return array{at: ?string, status: ?string, count_30d: int} */
    protected function lastStats(string $platform): array
    {
        $latest = ImageSocialPost::where('platform', $platform)->latest('id')->first();

        $count30d = ImageSocialPost::where('platform', $platform)
            ->where('status', 'published')
            ->where('published_at', '>=', Carbon::now()->subDays(30))
            ->count();

        return [
            'at' => optional($latest?->published_at ?? $latest?->created_at)->toIso8601String(),
            'status' => $latest?->status,
            'count_30d' => $count30d,
        ];
    }
}
