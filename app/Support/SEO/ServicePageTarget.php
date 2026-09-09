<?php

namespace App\Support\SEO;

use App\Services\Seo\TitleMetaGenerator;

/**
 * A money page (/services/{slug}) as an autopilot target. Service pages are
 * Blade/Livewire templates, not rows, so there is no model to bind; the
 * title/meta rewrite lands as a path override exactly like every other
 * title_meta action.
 */
final class ServicePageTarget
{
    public function __construct(public readonly string $slug) {}

    public function getKey(): string
    {
        return $this->slug;
    }

    public function label(): string
    {
        return TitleMetaGenerator::SERVICES[$this->slug] ?? ucwords(str_replace('-', ' ', $this->slug));
    }

    public function path(): string
    {
        return '/services/' . $this->slug;
    }
}
