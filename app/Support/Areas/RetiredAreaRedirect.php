<?php

namespace App\Support\Areas;

use App\Models\AreaServed;
use Illuminate\Support\Str;

/**
 * Where a page for a town the site no longer serves should send a visitor
 * — and Google, which keeps crawling the old /areas-served/{town} pages
 * (and their services/projects/testimonials spokes) long after the town
 * left the areas list, and files every one as a "Not found (404)".
 *
 * The nearest town still served, by the bundled gazetteer's coordinates
 * for the old town (TownCatalog), keeps the same spoke (a removed town's kitchen-remodeling page
 * lands on its neighbour's); a town the gazetteer does not know goes to
 * the areas index. Only slugs that really are unknown reach this: a served
 * town's own routes match first.
 */
class RetiredAreaRedirect
{
    public const INDEX = '/areas-served';

    /**
     * The 301 target for /areas-served/{slug}{suffix}, or null when {slug}
     * is a served town (nothing to redirect).
     */
    public static function target(string $slug, string $suffix = ''): ?string
    {
        $slug = Str::slug($slug);
        if ($slug === '' || AreaServed::query()->where('slug', $slug)->exists()) {
            return null;
        }

        $nearest = static::nearestServed($slug);

        return $nearest ? self::INDEX.'/'.$nearest->slug.$suffix : self::INDEX;
    }

    /** The served town closest to the retired one, when the gazetteer (TownCatalog) places it. */
    public static function nearestServed(string $slug): ?AreaServed
    {
        $town = TownCatalog::find($slug);
        if (! $town || ! isset($town['lat'], $town['lng'])) {
            return null;
        }

        return AreaServed::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->sortBy(fn (AreaServed $a) => static::distance((float) $town['lat'], (float) $town['lng'], (float) $a->latitude, (float) $a->longitude))
            ->first();
    }

    /** Great-circle distance in miles. */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 3958.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
