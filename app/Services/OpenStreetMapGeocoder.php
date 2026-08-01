<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OpenStreetMapGeocoder
{
    /**
     * Total wall-clock budget for one Overpass lookup, across all endpoints.
     *
     * Measured: on a bad day every endpoint gateway-times-out, and an
     * unbudgeted 3-endpoint loop took 32 seconds — inside a Livewire request,
     * with the user staring at a map. Fail fast and let the client retry.
     */
    private const OVERPASS_BUDGET_SECONDS = 6.0;

    /**
     * @return array{0: float|null, 1: float|null}
     */
    public function geocodeCity(string $city, string $state = 'IL', string $country = 'USA'): array
    {
        $city = trim($city);
        if ($city === '') {
            return [null, null];
        }

        $cacheKey = sprintf('osm:geocode:%s|%s|%s', mb_strtolower($city), mb_strtolower($state), mb_strtolower($country));

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['lat'], $cached['lng'])) {
            return [(float) $cached['lat'], (float) $cached['lng']];
        }

        $query = trim($city . ($state !== '' ? ', ' . $state : '') . ($country !== '' ? ', ' . $country : ''));

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'GS-Construction-GBP-Geotag/1.0 (' . config('app.url') . ')',
                'Accept' => 'application/json',
            ])
                ->timeout(15)
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 1,
                    'addressdetails' => 0,
                ]);
        } catch (\Throwable) {
            return [null, null];
        }

        if (! $response->successful()) {
            return [null, null];
        }

        $first = $response->json(0);
        if (! is_array($first) || ! isset($first['lat'], $first['lon'])) {
            return [null, null];
        }

        $lat = (float) $first['lat'];
        $lng = (float) $first['lon'];

        Cache::put($cacheKey, ['lat' => $lat, 'lng' => $lng], now()->addDays(30));

        return [$lat, $lng];
    }

    /**
     * Reverse-geocode a map click to the town under it.
     *
     * Replaces the browser-side Google Geocoder in the areas admin: that call
     * is referer-restricted, so it dies with REQUEST_DENIED on every
     * *.localhost dev host and would again on each new preview host. Server
     * side there is no referer to restrict, and Nominatim is the service this
     * app already geocodes with.
     *
     * State comes from ISO3166-2-lvl4 ("US-IL") rather than the spelled-out
     * state name, so callers can compare against the two-letter codes used in
     * per-site config. Only successful lookups are cached — a transient
     * network failure must not pin "no town here" for a month.
     *
     * @return array{city: string|null, state: string|null}
     */
    public function reverseTown(float $lat, float $lng): array
    {
        // ~11 m precision: clicks in the same spot share a cache row.
        $cacheKey = sprintf('osm:reverse:%.4f,%.4f', $lat, $lng);

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && array_key_exists('city', $cached)) {
            return $cached;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'GS-Construction-GBP-Geotag/1.0 (' . config('app.url') . ')',
                'Accept' => 'application/json',
            ])
                ->timeout(15)
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    // Locality granularity — we want the town, not the parcel.
                    'zoom' => 10,
                    'addressdetails' => 1,
                ]);
        } catch (\Throwable) {
            return ['city' => null, 'state' => null];
        }

        if (! $response->successful()) {
            return ['city' => null, 'state' => null];
        }

        $addr = (array) $response->json('address', []);

        $city = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['hamlet'] ?? $addr['municipality'] ?? null;
        $iso = (string) ($addr['ISO3166-2-lvl4'] ?? '');
        $state = str_starts_with($iso, 'US-') ? substr($iso, 3) : ($addr['state'] ?? null);

        $result = ['city' => is_string($city) ? $city : null, 'state' => is_string($state) ? $state : null];

        if ($result['city'] !== null) {
            Cache::put($cacheKey, $result, now()->addDays(30));
        }

        return $result;
    }

    /**
     * Every named town inside a map viewport, from OpenStreetMap via Overpass.
     *
     * Feeds the candidate dots on the areas admin map: place=city|town|village
     * nodes, each carrying its own name and coordinates, so adding one needs
     * no geocoding at all. Cached per rounded bbox — panning wiggles hit the
     * same cache row instead of Overpass.
     *
     * $budgetSeconds: total wall clock across all endpoints. The default is a
     * fail-fast 6s for anything running inside a request; towns:import passes a
     * generous value because nobody is waiting on it.
     *
     * @return array<int, array{name: string, lat: float, lng: float}>|null
     *         null = the lookup itself failed (distinct from "no towns here")
     */
    public function townsInBounds(
        float $south,
        float $west,
        float $north,
        float $east,
        ?float $budgetSeconds = null,
    ): ?array {
        $cacheKey = sprintf('osm:towns:%.2f,%.2f,%.2f,%.2f', $south, $west, $north, $east);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $query = sprintf(
            '[out:json][timeout:25];node["place"~"^(city|town|village)$"](%.4f,%.4f,%.4f,%.4f);out 800;',
            $south, $west, $north, $east,
        );

        // Overpass throttles hard and answers an HTML error page rather than a
        // status code when it does, so a "successful" response can still be
        // HTML. Try the mirrors in turn and require actual JSON before
        // believing an answer — otherwise a throttle reads as "no towns here"
        // and the candidate dots silently vanish.
        // HARD total budget. This runs inside a Livewire request while the user
        // watches the map, so it must fail fast rather than answer late: three
        // endpoints at 12s each produced measured 32-second hangs when Overpass
        // was having a bad day. Better to show no dots for one viewport and let
        // the client retry than to freeze the admin.
        $deadline = microtime(true) + ($budgetSeconds ?? self::OVERPASS_BUDGET_SECONDS);
        $response = null;

        foreach ([
            'https://overpass-api.de/api/interpreter',
            'https://overpass.kumi.systems/api/interpreter',
            'https://overpass.private.coffee/api/interpreter',
        ] as $endpoint) {
            $remaining = $deadline - microtime(true);
            if ($remaining < 1.5) {
                break;
            }

            try {
                $attempt = Http::withHeaders([
                    'User-Agent' => 'GS-Construction-GBP-Geotag/1.0 (' . config('app.url') . ')',
                    'Accept' => 'application/json',
                ])
                    ->asForm()
                    ->timeout((int) ceil($remaining))
                    ->post($endpoint, ['data' => $query]);
            } catch (\Throwable) {
                continue;
            }

            if ($attempt->successful() && is_array($attempt->json('elements'))) {
                $response = $attempt;
                break;
            }
        }

        if ($response === null) {
            return null;
        }

        $towns = collect($response->json('elements', []))
            ->filter(fn ($e) => isset($e['tags']['name'], $e['lat'], $e['lon']))
            ->map(fn ($e) => [
                'name' => (string) $e['tags']['name'],
                'lat' => (float) $e['lat'],
                'lng' => (float) $e['lon'],
                // Kept for the gazetteer: state for disambiguation, kind so the
                // map can weight settlement size later.
                'state' => isset($e['tags']['is_in:state_code']) ? (string) $e['tags']['is_in:state_code'] : null,
                'kind' => (string) ($e['tags']['place'] ?? ''),
            ])
            ->values()
            ->all();

        Cache::put($cacheKey, $towns, now()->addHours(12));

        return $towns;
    }
}
