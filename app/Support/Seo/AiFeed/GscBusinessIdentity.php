<?php

namespace App\Support\Seo\AiFeed;

use App\Support\CompanyStats;
use SsSystems\Platform\Seo\AiFeed\Contracts\BusinessIdentity;

/**
 * This site's `business` block, moved verbatim out of the old
 * `AiFeedController` closure. Phone is deliberately absent here — see
 * `BusinessIdentity`'s docblock — `AiFeedBuilder` reads it from
 * `Reports\Contracts\SiteIdentity::expectedPhone()`
 * (`App\Support\Seo\Reports\ConfigSiteIdentity`), which reads the same
 * `geo-answers.meta.phone` config value this file used to hardcode as a
 * literal, so `business.phone` is unchanged.
 *
 * `hours`/`rating` ride in `extra()` since they have no equivalent on
 * jpeterson-design's `business` block.
 */
final class GscBusinessIdentity implements BusinessIdentity
{
    public function name(): string
    {
        return 'GS Construction';
    }

    public function legalName(): ?string
    {
        return 'GS Construction & Remodeling';
    }

    public function url(): string
    {
        return 'https://gs.construction';
    }

    public function email(): ?string
    {
        return 'crew@gs.construction';
    }

    public function founded(): ?string
    {
        return '2015';
    }

    public function languages(): array
    {
        return ['English', 'Polish'];
    }

    public function addressLocality(): ?string
    {
        return 'Arlington Heights';
    }

    public function addressRegion(): ?string
    {
        return 'IL';
    }

    public function addressCountry(): string
    {
        return 'US';
    }

    public function social(): array
    {
        return array_filter([
            'facebook' => config('socials.facebook.url'),
            'instagram' => config('socials.instagram.url'),
            'google' => config('socials.google.url'),
            'houzz' => config('socials.houzz.url'),
            'yelp' => config('socials.yelp.url'),
            'angi' => config('socials.angi.url'),
        ]);
    }

    public function extra(): array
    {
        return [
            'hours' => 'Mon-Sat 08:00-18:00 America/Chicago',
            'rating' => [
                'value' => 5,
                'count' => CompanyStats::reviewsTotal(),
                'sources' => ['Google', 'Houzz', 'Yelp', 'Angi'],
            ],
        ];
    }
}
