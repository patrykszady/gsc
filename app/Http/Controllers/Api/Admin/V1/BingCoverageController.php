<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunBingCoverageJob;
use App\Models\BingCrawlStat;
use App\Models\BingUrlInfo;
use SsSystems\Platform\Seo\Bing\Http\Concerns\ServesBingCoverage;

/**
 * The admin API behind the hub's Bing card (GET seo/bing-coverage, POST
 * seo/bing-coverage/refresh), next to GscErrorController. index()/
 * refresh() come from the kit's ServesBingCoverage trait (kit 0.22.0,
 * 2026-11-10); this class only supplies this site's own two BelongsToSite
 * models and how the Refresh button queues `seo:bing-coverage`, the same
 * way GscErrorController::dispatchGscInspectBulkSweep() queues its sweep.
 */
class BingCoverageController extends Controller
{
    use ServesBingCoverage;

    protected function bingCrawlStatModel(): string
    {
        return BingCrawlStat::class;
    }

    protected function bingUrlInfoModel(): string
    {
        return BingUrlInfo::class;
    }

    protected function dispatchBingCoverageSync(): void
    {
        RunBingCoverageJob::dispatch();
    }
}
