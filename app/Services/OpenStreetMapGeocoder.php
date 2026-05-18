<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OpenStreetMapGeocoder
{
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
}
