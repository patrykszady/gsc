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

    /**
     * The gazetteer town nearest a point, within $maxKm — hamlets included.
     *
     * This is how a click on a place label that has no dot gets its right name,
     * instantly and locally. Reverse-geocoding the same point answers with the
     * administrative unit instead: the "Riverside" label comes back as "Lincoln
     * Charter Township", which is correct and useless to someone adding a
     * service area.
     *
     * Returning null when nothing is close is load-bearing — it is what keeps a
     * click on open countryside from offering to add whatever was nearest,
     * which is how stray clicks used to become service areas.
     */
    public static function nearestTo(float $lat, float $lng, float $maxKm = 4.0): ?self
    {
        // A degree of latitude is ~111km; longitude shrinks with latitude. A
        // cheap box first so the distance maths only runs on a handful of rows.
        $latPad = $maxKm / 111.0;
        $lngPad = $maxKm / max(1.0, 111.0 * cos(deg2rad($lat)));

        return static::query()
            ->whereBetween('latitude', [$lat - $latPad, $lat + $latPad])
            ->whereBetween('longitude', [$lng - $lngPad, $lng + $lngPad])
            ->get()
            ->map(fn (self $town) => [
                'town' => $town,
                'km' => static::kmBetween($lat, $lng, (float) $town->latitude, (float) $town->longitude),
            ])
            ->filter(fn (array $row) => $row['km'] <= $maxKm)
            ->sortBy('km')
            ->first()['town'] ?? null;
    }

    public static function kmBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $x = deg2rad($lng2 - $lng1) * cos(deg2rad(($lat1 + $lat2) / 2));
        $y = deg2rad($lat2 - $lat1);

        return sqrt($x * $x + $y * $y) * 6371.0;
    }
}
