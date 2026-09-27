<?php

namespace App\Support\Social;

use App\Services\GoogleBusinessProfileService;
use App\Services\MetaSocialService;
use SsSystems\Platform\Social\Contracts\PlatformAvailability;

class KitPlatformAvailability implements PlatformAvailability
{
    public function __construct(
        protected MetaSocialService $meta,
        protected GoogleBusinessProfileService $gbp,
    ) {}

    public function isConfigured(string $platform): bool
    {
        return match ($platform) {
            'instagram' => $this->meta->isInstagramConfigured(),
            'facebook' => $this->meta->isFacebookConfigured(),
            'google_business' => $this->gbp->isConfigured(),
            default => false,
        };
    }
}
