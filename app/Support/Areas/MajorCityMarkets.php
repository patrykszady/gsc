<?php

namespace App\Support\Areas;

/**
 * Whether a point sits inside one of the CURRENT tenant's "major-city"
 * markets — the box towns:import draws around a market centre for its
 * neighbourhood/suburb/quarter pass (config('areas.neighbourhood_radius_degrees'),
 * the exact degrees value TownsImport::neighbourhoodBoxes() also reads). One
 * shared radius means the two can never drift apart: any neighbourhood
 * towns:import actually stored is, by construction, inside a market this
 * class says yes to, and this class never says yes to a point outside every
 * market's box.
 *
 * This is the rule the owner asked for after seeing 128 neighbourhood dots
 * on the Atlanta coverage map: neighbourhoods are no longer dotted (the
 * admin decides that), but they must still be SEARCHABLE and ADDABLE, and
 * only inside a major city — TownCatalog::candidates() and
 * AreaMapController::createFromMap both gate on this one decision so they
 * can never disagree about what counts as "inside".
 *
 * config('markets.list') is a per-tenant overlay (App\Support\SiteConfig) —
 * jpeterson-design's site config declares three metros, gs.construction's
 * declares none today (its own coverage is the AreaServed lattice, not a
 * named-market model). Reading plain config() rather than taking a Site
 * argument means this always answers for whichever tenant is bound, exactly
 * like TownsImport's own per-site loop (Tenancy::for($site, ...)) — and,
 * with no markets declared, it answers "no" everywhere, which is the honest
 * answer for a site that has not defined a major city.
 */
class MajorCityMarkets
{
    /**
     * Longitude degrees are narrower than latitude at US latitudes; widening
     * keeps the box roughly square on screen — the same factor
     * TownsImport::marketBoxes() widens by, so the box drawn here for the
     * addability check is identical to the one Overpass was actually asked
     * to fill.
     */
    private const LNG_WIDEN = 1.35;

    /** The shared radius default — see config/areas.php. */
    public static function radiusDegrees(): float
    {
        return (float) config('areas.neighbourhood_radius_degrees', 0.20);
    }

    /**
     * The box around one market centre, at $radiusDegrees (default: the
     * shared config value) — same shape TownsImport draws for its
     * neighbourhood pass.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} south, west, north, east
     */
    public static function boxAround(float $lat, float $lng, ?float $radiusDegrees = null): array
    {
        $radius = $radiusDegrees ?? self::radiusDegrees();

        return [
            $lat - $radius,
            $lng - $radius * self::LNG_WIDEN,
            $lat + $radius,
            $lng + $radius * self::LNG_WIDEN,
        ];
    }

    /**
     * The first of this tenant's declared markets whose neighbourhood box
     * contains $lat/$lng, or null when none does (including when the tenant
     * declares no markets at all).
     *
     * @return array<string, mixed>|null one row of config('markets.list')
     */
    public static function matchingMarket(float $lat, float $lng): ?array
    {
        foreach ((array) config('markets.list', []) as $market) {
            if (! isset($market['lat'], $market['lng'])) {
                continue;
            }

            [$south, $west, $north, $east] = self::boxAround((float) $market['lat'], (float) $market['lng']);

            if ($lat >= $south && $lat <= $north && $lng >= $west && $lng <= $east) {
                return $market;
            }
        }

        return null;
    }

    /** Is $lat/$lng inside ANY of this tenant's major-city markets? */
    public static function contains(float $lat, float $lng): bool
    {
        return self::matchingMarket($lat, $lng) !== null;
    }
}
