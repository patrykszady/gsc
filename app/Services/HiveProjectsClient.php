<?php

namespace App\Services;

use App\Models\HiveProjectZipCount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class HiveProjectsClient
{
    public function __construct(
        protected ?string $baseUrl = null,
        protected ?string $token = null,
    ) {
        $this->baseUrl ??= (string) config('services.hive.url');
        $this->token ??= (string) config('services.hive.token');
    }

    /**
     * Read the locally-stored zip counts (no network), aggregated by zip.
     * Returns Collection<{zip: string, count: int}> sorted desc — for the map heatmap.
     */
    public function storedZipCounts(): Collection
    {
        return HiveProjectZipCount::query()
            ->selectRaw('zip, SUM(`count`) as total')
            ->groupBy('zip')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['zip' => $row->zip, 'count' => (int) $row->total]);
    }

    /**
     * Read the locally-stored project counts (no network), aggregated by city.
     * Returns Collection<{city, state, lat, lng, count}> sorted desc.
     * Used by the heatmap. Rows without coordinates are skipped (they can't
     * be plotted).
     */
    public function storedCityCounts(): Collection
    {
        return HiveProjectZipCount::query()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw('city, MAX(state) as state, MAX(latitude) as lat, MAX(longitude) as lng, SUM(`count`) as total')
            ->groupBy('city')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'city' => $row->city,
                'state' => $row->state,
                'lat' => (float) $row->lat,
                'lng' => (float) $row->lng,
                'count' => (int) $row->total,
            ]);
    }

    /**
     * Per-zip points for the map overlay. One row per distinct ZIP
     * (counts summed across cities sharing the zip) so the bubble sits
     * on the true ZIP centroid rather than any one city's center.
     * Returns Collection<{zip, lat, lng, count}> sorted desc by count.
     */
    public function storedZipPoints(): Collection
    {
        return HiveProjectZipCount::query()
            ->whereNotNull('zip_latitude')
            ->whereNotNull('zip_longitude')
            ->selectRaw('zip, MAX(zip_latitude) as lat, MAX(zip_longitude) as lng, SUM(`count`) as total')
            ->groupBy('zip')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'zip' => $row->zip,
                'lat' => (float) $row->lat,
                'lng' => (float) $row->lng,
                'count' => (int) $row->total,
            ]);
    }

    /**
     * Distinct zip codes currently stored.
     *
     * @return array<int, string>
     */
    public function storedZips(): array
    {
        return HiveProjectZipCount::query()
            ->select('zip')
            ->distinct()
            ->orderBy('zip')
            ->pluck('zip')
            ->all();
    }

    /**
     * Zip codes associated with a given city name (case-insensitive).
     *
     * @return array<int, string>
     */
    public function zipsForCity(string $city): array
    {
        $city = trim($city);
        if ($city === '') {
            return [];
        }

        return HiveProjectZipCount::query()
            ->whereRaw('LOWER(city) = ?', [mb_strtolower($city)])
            ->select('zip')
            ->distinct()
            ->orderBy('zip')
            ->pluck('zip')
            ->all();
    }

    /**
     * Timestamp of the most recent successful sync, or null if never synced.
     */
    public function lastSyncedAt(): ?Carbon
    {
        $value = HiveProjectZipCount::query()->max('synced_at');
        return $value ? Carbon::parse($value) : null;
    }

    /**
     * POST a contact-form lead to hive.contractors. Returns the Hive lead id
     * on success, or throws RuntimeException on transient/permanent failure
     * so the queued job can decide whether to retry.
     *
     * Hive endpoint: POST {HIVE_API_URL}/api/v1/leads
     * Auth: Bearer HIVE_API_TOKEN (same token as zip-counts).
     *
     * Payload (mirrors Hive's Lead model fields — keep keys snake_case):
     *   name, email, phone, address, city, message, availability (array|null),
     *   source ("gs.construction"), referrer, ip_address, user_agent,
     *   utm_source, utm_medium, utm_campaign, submitted_at (ISO-8601),
     *   external_id (gsc contact_submissions.id, lets Hive dedupe).
     */
    public function submitLead(array $payload): int
    {
        if ($this->token === '' || $this->baseUrl === '') {
            throw new RuntimeException('HIVE_API_URL or HIVE_API_TOKEN is not configured.');
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->token)
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->retry(2, 500, throw: false)
                ->post('/api/v1/leads', $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach hive.contractors: ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new RuntimeException('Hive API call failed: ' . $e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $body = mb_substr((string) $response->body(), 0, 500);
            throw new RuntimeException("Hive /api/v1/leads returned HTTP {$response->status()}: {$body}");
        }

        $id = (int) ($response->json('data.id') ?? $response->json('id') ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Hive /api/v1/leads accepted but returned no id: '
                . mb_substr((string) $response->body(), 0, 300));
        }
        return $id;
    }

    /**
     * Fetch fresh zip counts from hive.contractors and overwrite the local table.
     * Returns the number of rows persisted. Throws on failure.
     */
    public function sync(): int
    {
        if ($this->token === '' || $this->baseUrl === '') {
            throw new RuntimeException('HIVE_API_URL or HIVE_API_TOKEN is not configured.');
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->token)
                ->acceptJson()
                ->timeout(15)
                ->connectTimeout(5)
                ->retry(2, 500, throw: false)
                ->get('/api/v1/projects/zip-counts');
        } catch (ConnectionException $e) {
            Log::error('HiveProjectsClient sync connection failure', ['error' => $e->getMessage()]);
            throw new RuntimeException('Could not reach hive.contractors: ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            Log::error('HiveProjectsClient sync unexpected error', ['error' => $e->getMessage()]);
            throw new RuntimeException('Hive API call failed: ' . $e->getMessage(), 0, $e);
        }

        if (!$response->successful()) {
            Log::error('HiveProjectsClient sync non-2xx', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);
            throw new RuntimeException('Hive API returned HTTP ' . $response->status());
        }

        $rows = $response->json('data');
        if (!is_array($rows)) {
            throw new RuntimeException('Hive API returned malformed payload (no "data" array).');
        }

        $now = now();
        // Build lookups so we can preserve previously-geocoded coordinates
        // and avoid re-hitting Nominatim:
        //   - cityCoords: keyed by (city,state) — city centroid (drives big bubbles).
        //   - zipCoords:  keyed by zip       — ZIP centroid (drives small bubbles).
        $existing = HiveProjectZipCount::query()
            ->get(['zip', 'city', 'state', 'latitude', 'longitude', 'zip_latitude', 'zip_longitude']);

        $cityCoords = $existing
            ->filter(fn ($r) => $r->latitude !== null && $r->longitude !== null && $r->city)
            ->mapWithKeys(fn ($r) => [
                mb_strtolower(trim((string) $r->city)) . '|' . mb_strtolower(trim((string) $r->state))
                    => ['lat' => (float) $r->latitude, 'lng' => (float) $r->longitude],
            ])
            ->all();

        $zipCoords = $existing
            ->filter(fn ($r) => $r->zip_latitude !== null && $r->zip_longitude !== null)
            ->mapWithKeys(fn ($r) => [
                trim((string) $r->zip)
                    => ['lat' => (float) $r->zip_latitude, 'lng' => (float) $r->zip_longitude],
            ])
            ->all();

        $records = collect($rows)
            ->map(function ($row) use ($now, $cityCoords, $zipCoords) {
                $zip = trim((string) ($row['zip'] ?? ''));
                $count = (int) ($row['count'] ?? 0);
                if ($zip === '' || $count <= 0) {
                    return null;
                }
                $city = isset($row['city']) ? trim((string) $row['city']) : '';
                $state = isset($row['state']) ? trim((string) $row['state']) : '';
                $cityKey = mb_strtolower($city) . '|' . mb_strtolower($state);
                $city = $cityCoords[$cityKey] ?? ['lat' => null, 'lng' => null];
                $zipPt = $zipCoords[$zip] ?? ['lat' => null, 'lng' => null];
                return [
                    'zip' => $zip,
                    'city' => isset($row['city']) && trim((string) $row['city']) !== '' ? trim((string) $row['city']) : null,
                    'state' => $state !== '' ? $state : null,
                    'latitude' => $city['lat'],
                    'longitude' => $city['lng'],
                    'zip_latitude' => $zipPt['lat'],
                    'zip_longitude' => $zipPt['lng'],
                    'count' => $count,
                    'synced_at' => $now,
                ];
            })
            ->filter()
            ->values()
            ->all();

        if (empty($records)) {
            throw new RuntimeException('Hive API returned zero usable rows; refusing to wipe local table.');
        }

        DB::transaction(function () use ($records) {
            // Note: TRUNCATE causes an implicit commit in MySQL, so we use DELETE
            // here to stay inside the transaction for atomic replacement.
            HiveProjectZipCount::query()->delete();
            foreach (array_chunk($records, 500) as $chunk) {
                HiveProjectZipCount::query()->insert($chunk);
            }
        });

        // Fill in any missing coordinates via Nominatim (free, no key).
        $this->geocodeMissing();

        return count($records);
    }

    /**
     * Populate any missing coordinates via OpenStreetMap Nominatim:
     *   - latitude/longitude          → city+state centroid (per distinct city)
     *   - zip_latitude/zip_longitude  → ZIP centroid (per distinct ZIP)
     * Respects the 1 req/sec usage policy. Failures are logged and skipped.
     */
    public function geocodeMissing(): int
    {
        $resolved = 0;

        // 1) City coords — distinct (city, state) lacking lat/lng.
        $missingCities = HiveProjectZipCount::query()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->where(function ($q) {
                $q->whereNull('latitude')->orWhereNull('longitude');
            })
            ->select('city', 'state')
            ->distinct()
            ->get();

        foreach ($missingCities as $row) {
            $city = (string) $row->city;
            $state = (string) ($row->state ?? '');
            $coords = $this->nominatimGeocodeCity($city, $state);
            usleep(1_100_000);

            if (!$coords) {
                Log::warning('HiveProjectsClient city geocode miss', compact('city', 'state'));
                continue;
            }

            HiveProjectZipCount::query()
                ->where('city', $city)
                ->where('state', $state)
                ->update(['latitude' => $coords['lat'], 'longitude' => $coords['lng']]);
            $resolved++;
        }

        // 2) ZIP coords — distinct ZIP lacking zip_lat/zip_lng.
        $missingZips = HiveProjectZipCount::query()
            ->where(function ($q) {
                $q->whereNull('zip_latitude')->orWhereNull('zip_longitude');
            })
            ->select('zip')
            ->distinct()
            ->pluck('zip');

        foreach ($missingZips as $zip) {
            $zip = (string) $zip;
            $coords = $this->nominatimGeocodeZip($zip);
            usleep(1_100_000);

            if (!$coords) {
                Log::warning('HiveProjectsClient zip geocode miss', compact('zip'));
                continue;
            }

            HiveProjectZipCount::query()
                ->where('zip', $zip)
                ->update(['zip_latitude' => $coords['lat'], 'zip_longitude' => $coords['lng']]);
            $resolved++;
        }

        return $resolved;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    protected function nominatimGeocodeZip(string $zip): ?array
    {
        if ($zip === '') {
            return null;
        }
        return $this->nominatimRequest(['postalcode' => $zip, 'country' => 'USA']);
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    protected function nominatimGeocodeCity(string $city, string $state): ?array
    {
        if ($city === '') {
            return null;
        }
        return $this->nominatimRequest(array_filter([
            'city' => $city,
            'state' => $state !== '' ? $state : null,
            'country' => 'USA',
        ]));
    }

    /**
     * @param  array<string, string>  $params
     * @return array{lat: float, lng: float}|null
     */
    protected function nominatimRequest(array $params): ?array
    {
        $params = array_merge([
            'format' => 'json',
            'limit' => 1,
            'countrycodes' => 'us',
        ], $params);

        try {
            $response = Http::withHeaders([
                    // Nominatim requires a descriptive UA identifying the app.
                    'User-Agent' => 'gs.construction hive-sync (contact: patryk@gs.construction)',
                    'Accept' => 'application/json',
                ])
                ->timeout(8)
                ->get('https://nominatim.openstreetmap.org/search', $params);
        } catch (Throwable $e) {
            Log::warning('Nominatim request failed', ['params' => $params, 'error' => $e->getMessage()]);
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $hit = $response->json(0);
        if (!is_array($hit) || !isset($hit['lat'], $hit['lon'])) {
            return null;
        }

        return [
            'lat' => (float) $hit['lat'],
            'lng' => (float) $hit['lon'],
        ];
    }
}
