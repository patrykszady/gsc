<?php

namespace App\Support\Seo\AiFeed;

use App\Models\AreaServed;
use SsSystems\Platform\Seo\AiFeed\Contracts\ServiceAreaCatalog;

/**
 * Moved verbatim out of the old `AiFeedController` closure — same flat
 * per-suburb matrix, same service-slug list. `AiFeedController::__invoke()`
 * keeps building the flat `service_area` city list itself (that key has no
 * equivalent on jpeterson-design, so it isn't this contract's job — see
 * `ServiceAreaCatalog`'s docblock) and passes it through `AiFeedBuilder`'s
 * `$extra`.
 */
final class GscServiceAreaCatalog implements ServiceAreaCatalog
{
    /** Kept in step with the flat 5-entry catalog `GscServiceCatalog` now reads live — this list still drives per-service matrix columns, not the feed's own `services` array. */
    private const SERVICE_SLUGS = ['kitchen-remodeling', 'bathroom-remodeling', 'home-remodeling', 'basement-remodeling', 'home-additions'];

    public function serviceAreaMatrix(): array
    {
        return $this->areas()->map(function (AreaServed $a): array {
            $row = [
                'city' => $a->city,
                'state' => 'IL',
                'url' => $a->url,
                'latitude' => $a->latitude,
                'longitude' => $a->longitude,
                'zipcodes' => $a->postalCodes(),
                'services' => [],
            ];
            foreach (self::SERVICE_SLUGS as $slug) {
                $row['services'][$slug] = $a->serviceUrl($slug);
            }

            return $row;
        })->values()->all();
    }

    private function areas()
    {
        return AreaServed::orderBy('city')->get();
    }
}
