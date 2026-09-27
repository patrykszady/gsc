<?php

namespace App\Http\Controllers;

use App\Models\AreaServed;
use App\Support\Seo\AiFeed\GscBusinessIdentity;
use App\Support\Seo\AiFeed\GscContentCatalog;
use App\Support\Seo\AiFeed\GscProjectCatalog;
use App\Support\Seo\AiFeed\GscReviewCatalog;
use App\Support\Seo\AiFeed\GscServiceAreaCatalog;
use App\Support\Seo\AiFeed\GscServiceCatalog;
use Illuminate\Http\JsonResponse;
use SsSystems\Platform\Reports\Contracts\SiteIdentity;
use SsSystems\Platform\Seo\AiFeed\AiFeedBuilder;

/**
 * Machine-readable structured feed for AI crawlers (ChatGPT, Perplexity,
 * Google AI Overviews, Claude, etc.). Linked from llms.txt and robots.txt
 * so generative engines can ingest a clean, current snapshot of the
 * business without scraping rendered pages.
 *
 * Kit port (2026-09-27, `SsSystems\Platform\Seo\AiFeed\AiFeedBuilder`) —
 * this controller now only wires this site's own adapters together; the
 * envelope shape, response headers and caching live in the kit. Along the
 * way: `services` reads the real `App\Models\Service` catalog instead of a
 * hardcoded 5-entry array that had drifted out of sync with it — see
 * `GscServiceCatalog`'s docblock.
 */
class AiFeedController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return $this->builder()->respond();
    }

    private function builder(): AiFeedBuilder
    {
        return new AiFeedBuilder(
            business: new GscBusinessIdentity,
            identity: app(SiteIdentity::class),
            services: new GscServiceCatalog,
            projects: new GscProjectCatalog,
            content: new GscContentCatalog,
            reviews: new GscReviewCatalog,
            areas: new GscServiceAreaCatalog,
            links: [
                'sitemap' => url('/sitemap.xml'),
                'llms_txt' => url('/llms.txt'),
                'llms_full' => url('/llms-full.txt'),
                'robots' => url('/robots.txt'),
            ],
            extra: [
                '$schema' => 'https://gs.construction/ai-feed.schema.json',
                'service_area' => AreaServed::orderBy('city')->pluck('city')->all(),
                'credentials' => [
                    'licensed' => true,
                    'insured' => true,
                    'bonded' => true,
                    'response_time_sla' => 'Within 1 business day',
                    'consultation' => 'Free in-home estimate',
                ],
            ],
        );
    }
}
