<?php

namespace App\Support\Seo\Reports;

use SsSystems\Platform\Reports\Contracts\SiteIdentity;

/**
 * The NAP + GBP-service-catalog facts GbpParityReport reads, through the
 * exact same config chain SeoGbpParity read directly:
 * geo-answers.meta.phone ?? seo.phone ?? socials.phone ?? services.business.phone
 * for phone, seo.address ?? socials.address ?? services.business.address for
 * address, and gbp-services.services (falling back to the whole gbp-services
 * file) for the service catalog — the kit's `site_identity` capability.
 */
final class ConfigSiteIdentity implements SiteIdentity
{
    public function expectedPhone(): ?string
    {
        $phone = config('geo-answers.meta.phone')
            ?? config('seo.phone')
            ?? config('socials.phone')
            ?? config('services.business.phone');

        return $phone !== null ? (string) $phone : null;
    }

    public function expectedAddress(): ?string
    {
        $address = config('seo.address')
            ?? config('socials.address')
            ?? config('services.business.address');

        return $address !== null ? (string) $address : null;
    }

    public function gbpServices(): array
    {
        return (array) config('gbp-services.services', config('gbp-services', []));
    }

    public function gbpServiceSlugAliases(): array
    {
        return (array) config('gbp-services.slug_aliases', []);
    }
}
