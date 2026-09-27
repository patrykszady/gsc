<?php

namespace App\Services\Social;

use App\Services\GoogleBusinessProfileService;
use App\Services\MetaSocialService;
use SsSystems\Platform\Social\Contracts\PlatformAvailability;

/**
 * The kit's PlatformAvailability contract, wrapping this site's own
 * MetaSocialService (instagram/facebook) and GoogleBusinessProfileService
 * (google_business) — the exact match statements the app-level
 * AutomationSettingsService used to run directly (2026-09-27).
 */
class SocialPlatformAvailability implements PlatformAvailability
{
    public function isConfigured(string $platform): bool
    {
        return match ($platform) {
            'instagram' => app(MetaSocialService::class)->isInstagramConfigured(),
            'facebook' => app(MetaSocialService::class)->isFacebookConfigured(),
            'google_business' => app(GoogleBusinessProfileService::class)->isConfigured(),
            default => false,
        };
    }

    public function isPublishingOff(string $platform): bool
    {
        $meta = app(MetaSocialService::class);

        // Google Business has no such switch any more: connected is ready.
        return match ($platform) {
            'instagram' => ! $meta->isPublishingEnabled() && $meta->isInstagramConnected(),
            'facebook' => ! $meta->isPublishingEnabled() && $meta->isFacebookConnected(),
            default => false,
        };
    }
}
