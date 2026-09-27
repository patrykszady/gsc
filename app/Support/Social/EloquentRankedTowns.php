<?php

namespace App\Support\Social;

use App\Models\AreaServed;
use SsSystems\Platform\Social\Contracts\RankedTowns;

/**
 * The kit's `ranked_towns` capability (kit 0.13.0), read straight through
 * `AreaServed::coreTowns()` — unchanged since before the port, so this
 * inherits its `BelongsToSite` scoping (and its own 6-hour, tenant-keyed
 * cache) without knowing anything about tenancy itself.
 */
final class EloquentRankedTowns implements RankedTowns
{
    public function coreTowns(int $n = 6): array
    {
        return AreaServed::coreTowns($n);
    }
}
