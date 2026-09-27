<?php

namespace App\Support\Citations;

use SsSystems\Platform\Citations\Contracts\KnownListingsSource;

/**
 * gsc's adapter for the kit's `Citations\KnownListingsReconciler`
 * (citations-admin-actions, 2026-09-27): delegates straight to this file's
 * own `KnownListings::forCurrentSite()`, unchanged — the matching logic
 * itself stays here (genuinely per-site content, see that class's own
 * docblock), only the reconcile LOOP moved to the kit.
 */
class SiteKnownListingsSource implements KnownListingsSource
{
    public function forCurrentSite(): array
    {
        return KnownListings::forCurrentSite();
    }
}
