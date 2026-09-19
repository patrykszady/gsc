<?php

namespace App\Support\Areas;

use Illuminate\Support\Facades\Cache;

/**
 * Which US state a point is in, answered from the bundled gazetteer instead of
 * over the internet.
 *
 * Adding a town from the coverage map took 4.6-7.3 seconds, and ~1.5s of every
 * one of those was a Nominatim reverse-geocode asking which state the click was
 * in — a question `resources/data/us-zips.json` (GeoNames, CC BY 4.0) can answer
 * locally for all 40,979 US ZIPs. The geocoder caches for 30 days, which never
 * helped: a town being added for the first time is always a cache miss, and that
 * is the only click that matters.
 *
 * The town's NAME is used before its coordinates. The map's orange dots come
 * from the OSM `towns` gazetteer, whose rows carry a name but no state (all 368
 * are null here), so the name is the strongest signal we have — and matching it
 * against a ZIP's city avoids the one way a nearest-point lookup goes wrong,
 * which is a click near a state line landing on the neighbour's centroid.
 *
 * Returns null rather than guessing when nothing is close, so the caller can
 * still fall back to the geocoder for a point this gazetteer cannot place.
 *
 * Kept BYTE-IDENTICAL in gsc and jpeterson-design (change it in one, copy it to
 * the other), along with the data file it reads.
 */
final class StateLocator
{
    public const CACHE_KEY = 'areas.state-locator.index';

    public const TTL_SECONDS = 86400;

    /**
     * Beyond this, a nearest-centroid answer is a guess rather than a lookup.
     * ZIP centroids are dense enough that a real town is far closer than this;
     * the allowance is for sparse rural coverage.
     */
    public const MAX_KM = 60.0;

    public static function forTown(string $city, float $lat, float $lng): ?string
    {
        $city = mb_strtolower(trim($city));

        $best = null;
        $bestDistance = null;

        foreach (self::index() as $entry) {
            [$entryCity, $state, $entryLat, $entryLng] = $entry;

            $distance = self::distanceKm($lat, $lng, $entryLat, $entryLng);

            // A name match wins outright, but only against other name matches:
            // "Springfield" exists in most states, so the nearest one is meant.
            if ($city !== '' && $entryCity === $city) {
                if ($best === null || $best[0] !== true || $distance < $bestDistance) {
                    $best = [true, $state];
                    $bestDistance = $distance;
                }

                continue;
            }

            if ($best !== null && $best[0] === true) {
                continue;
            }

            if ($bestDistance === null || $distance < $bestDistance) {
                $best = [false, $state];
                $bestDistance = $distance;
            }
        }

        if ($best === null || $bestDistance === null || $bestDistance > self::MAX_KM) {
            return null;
        }

        return $best[1] !== '' ? $best[1] : null;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * [city (lowercased), state, lat, lng] per ZIP.
     *
     * Flattened to a plain list on purpose: the source file is a 2.2MB object
     * keyed by ZIP, and the ZIP itself is never the question here.
     *
     * @return array<int, array{0: string, 1: string, 2: float, 3: float}>
     */
    protected static function index(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, function (): array {
            $path = resource_path('data/us-zips.json');

            if (! is_file($path)) {
                return [];
            }

            $raw = json_decode((string) file_get_contents($path), true);

            if (! is_array($raw)) {
                return [];
            }

            $index = [];

            foreach ($raw as $row) {
                // [city, state, county, lat, lng]
                if (! is_array($row) || count($row) < 5) {
                    continue;
                }

                $index[] = [
                    mb_strtolower(trim((string) $row[0])),
                    strtoupper(trim((string) $row[1])),
                    (float) $row[3],
                    (float) $row[4],
                ];
            }

            return $index;
        });
    }

    /** Equirectangular approximation — accurate well past MAX_KM and far cheaper than haversine across 41k rows. */
    protected static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $x = deg2rad($lng2 - $lng1) * cos(deg2rad(($lat1 + $lat2) / 2));
        $y = deg2rad($lat2 - $lat1);

        return sqrt($x * $x + $y * $y) * 6371.0;
    }
}
