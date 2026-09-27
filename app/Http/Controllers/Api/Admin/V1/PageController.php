<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Support\Pages\GscPageOverrideStore;
use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use SsSystems\Platform\Pages\Contracts\PageOverrideStore;
use SsSystems\Platform\Pages\Http\Concerns\ServesPages;

/**
 * ss-systems' "Pages" screen (the 'pages' ping domain) for gs.construction —
 * see App\Support\Pages\GscPageOverrideStore for the actual storage (a
 * path-keyed, tenant-scoped SeoPathOverride cache) and the kit's
 * SsSystems\Platform\Pages\Http\Concerns\ServesPages for the shared HTTP
 * shaping (index/types/show/update/store/destroy) every one of the four
 * sites' PageController now shares.
 */
class PageController extends Controller
{
    use BuildsApiResponses, ServesPages;

    protected function pageOverrideStore(): PageOverrideStore
    {
        return new GscPageOverrideStore;
    }
}
