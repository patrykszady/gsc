<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Support\Tenancy;
use SsSystems\Platform\Http\Admin\Concerns\BuildsTrafficSources;
use SsSystems\Platform\Traffic\TrafficDays;

/**
 * GET /api/admin/v1/traffic/sources — where visitors came from, per channel
 * and source, from every provider (kit 0.17's BuildsTrafficSources). The SEO
 * screen's "Where visitors come from" card reads it; traffic:collect fills it.
 */
class TrafficController extends Controller
{
    use BuildsTrafficSources;

    protected function trafficDays(): TrafficDays
    {
        return app(TrafficDays::class);
    }

    protected function trafficCacheKey(string $key): string
    {
        return Tenancy::cacheKey($key);
    }
}
