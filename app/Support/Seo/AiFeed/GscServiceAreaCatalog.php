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
            foreach ($a->serviceSlugs() as $slug) {
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
