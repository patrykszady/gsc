<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A town in the local gazetteer — the source for the service-area map's
 * candidate dots.
 *
 * Deliberately NOT site-scoped: a town is a fact about the world, so one
 * import serves every tenant. See the create_towns_table migration for why the
 * map stopped querying Overpass live.
 */
class Town extends Model
{
    protected $guarded = [];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    /** Everything inside a map viewport. */
    public function scopeInBounds(Builder $q, float $south, float $west, float $north, float $east): Builder
    {
        return $q->whereBetween('latitude', [$south, $north])
            ->whereBetween('longitude', [$west, $east]);
    }
}
