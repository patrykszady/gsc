<?php

namespace App\Support\Seo;

use App\Models\Site;
use SsSystems\Platform\Seo\Clarity\Contracts\ClaritySiteHosts;

/**
 * The current tenant's hosts for the kit's Clarity per-page sync
 * (seo:clarity-sync), built exactly as the retired App\Services\
 * MicrosoftClarityService::siteHosts() did: Site::current()'s primary_host
 * plus its extra hosts, lowercased with any leading www stripped.
 * ClarityClient::pageMetrics() keeps only rows on one of these, dropping
 * local dev and preview tunnels — which carry the same Clarity tag.
 */
final class GscClaritySiteHosts implements ClaritySiteHosts
{
    /** @return list<string> */
    public function hosts(): array
    {
        $site = Site::current();
        $hosts = array_merge([(string) $site->primary_host], (array) $site->hosts);

        return array_values(array_unique(array_filter(array_map(
            static fn ($host): string => (string) preg_replace('/^www\./', '', strtolower(trim((string) $host))),
            $hosts,
        ))));
    }
}
