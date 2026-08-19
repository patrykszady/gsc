<?php

namespace App\Services;

use App\Support\ApiErrorFormatter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Geoapify geocoding, trimmed to what LeadAddressCompleter needs.
 *
 * Ported from hive2025's app/Services/GeoapifyService.php (917 lines) —
 * ONLY the address-resolution surface came across: geocodeAddress(),
 * nearbyAddressCandidates(), and the parsing/anchoring helpers they call.
 * hive's autocomplete, place-details, county/zip lookups, map-URL builder
 * and vendor-location plumbing are NOT here; gsc has no autocomplete UI and
 * no Vendor model, so none of that has a caller.
 *
 * hive resolves an "office location" per-vendor (multi-tenant CRM); gsc is
 * a single business, so officeLocation() below is fixed to the coordinates
 * already published as this business's `geo` in
 * resources/views/components/schema-org.blade.php, rather than looking
 * anything up.
 */
class GeoapifyService
{
    protected $apiKey;

    protected $httpClient;

    public function __construct()
    {
        // Timeouts are NOT optional here: this client is called from the
        // contact-form request path. Guzzle's default is to wait forever,
        // so one slow geocode would hang lead submission — the lead capture
        // must always finish even when Geoapify does not.
        $this->httpClient = new Client([
            'timeout' => (float) config('services.geoapify.timeout', 4),
            'connect_timeout' => (float) config('services.geoapify.connect_timeout', 2),
        ]);
        $this->apiKey = (string) config('services.geoapify.key', '');
    }

    /**
     * Resolve a free-form address to its full components.
     *
     * Returns null unless the answer can be TRUSTED, which means the query has
     * to be anchored: a bare street is unanswerable, and the geocoder does not
     * say so — "511 Sherwood Dr" comes back as Rolla, Missouri with confidence
     * 1.0 and match_type full_match, because it simply picked the first
     * Sherwood Drive in the country. So we require a city / state / ZIP in the
     * query, and we require the result to agree with it.
     *
     * @return array{address: string, city: string, state: string, zip_code: string}|null
     */
    public function geocodeAddress(string $address): ?array
    {
        $address = trim($address);

        if ($address === '' || $this->apiKey === '') {
            return null;
        }

        $anchor = self::addressAnchor($address);

        if ($anchor === null) {
            Log::channel('submissions')->info('Geoapify: refusing to geocode an unanchored street', [
                'address' => $address,
            ]);

            return null;
        }

        $cacheKey = 'geoapify_full_'.md5(strtolower($address));

        // Successes are stable and cached for a month; FAILURES are not cached
        // for a month. A geocoder hiccup — or a lookup made before a bug was
        // fixed — otherwise pins that address as "unresolvable" until the key
        // expires, which is exactly how a lead sat incomplete for days.
        return $this->rememberResolved($cacheKey, function () use ($address, $anchor) {
            // Structured first: free text makes the geocoder guess which
            // "N Magnolia Ave" you meant and it answers 60642 (wrong) with
            // confidence 0.5, while the same address split into fields answers
            // 60660 (right) with confidence 1.
            $parts = self::splitForGeocoding($address);

            if ($parts !== null) {
                $result = $this->geocodeQuery($parts, $address, $anchor);

                if ($result !== null) {
                    return $result;
                }

                // Without a state the geocoder searches the whole country and
                // hedges: "5647 N Magnolia Ave, Chicago" comes back 60642 at
                // confidence 0.5, and "424 Broadview Ave., Highland Park"
                // comes back LOS ANGELES — Highland Park is an LA
                // neighbourhood too. Asking it for the state first is no good
                // (it answers CA); anchoring the search to the office is,
                // because that is where the work actually is.
                if (! isset($parts['state']) && ($office = $this->officeLocation()) !== null) {
                    $result = $this->geocodeQuery($parts + [
                        'filter' => 'circle:'.$office.','.self::SERVICE_RADIUS_METERS,
                        'bias' => 'proximity:'.$office,
                    ], $address, $anchor);

                    if ($result !== null) {
                        return $result;
                    }
                }
            }

            return $this->geocodeQuery(['text' => $address], $address, $anchor);
        });
    }

    /**
     * Every real address matching this street within reach of the office,
     * nearest first.
     *
     * A street with no city is genuinely ambiguous — "511 Sherwood Dr" exists
     * in Addison (12.0 mi) AND Streamwood (13.4 mi). Rather than let the
     * geocoder pick (it answers Rolla, Missouri) or silently take the nearest,
     * hand back the candidates so a human can say which one it is.
     *
     * @return array<int, array{address: string, city: string, state: string, zip_code: string, miles: float}>
     */
    public function nearbyAddressCandidates(string $address, int $limit = 5): array
    {
        $parts = self::splitForGeocoding($address);

        if ($parts === null) {
            // No house number, or nothing to search on.
            if (! preg_match('/^(\d+[A-Za-z]?)\s+(.+)$/', trim($address), $m)) {
                return [];
            }

            $parts = ['housenumber' => $m[1], 'street' => $m[2], 'country' => 'United States'];
        }

        $office = $this->officeLocation();

        if ($office === null || $this->apiKey === '') {
            return [];
        }

        $cacheKey = 'geoapify_nearby_'.md5(strtolower($address).$office);

        return $this->rememberResolved($cacheKey, function () use ($parts, $office, $address, $limit) {
            try {
                $response = $this->httpClient->get('https://api.geoapify.com/v1/geocode/search', [
                    'query' => $parts + [
                        'apiKey' => $this->apiKey,
                        'format' => 'json',
                        'limit' => 20,
                        // Constrain to the service area, then rank by distance:
                        // an unconstrained search returns the wrong state.
                        'filter' => 'circle:'.$office.','.self::SERVICE_RADIUS_METERS,
                        'bias' => 'proximity:'.$office,
                    ],
                ]);

                $data = json_decode($response->getBody(), true);
                $results = static::sortResultsByDistance($data['results'] ?? [], $office);

                [$originLon, $originLat] = array_pad(explode(',', $office), 2, null);

                return collect($results)
                    ->filter(function (array $result): bool {
                        return trim((string) ($result['housenumber'] ?? '')) !== ''
                            && trim((string) ($result['street'] ?? '')) !== ''
                            && trim((string) ($result['city'] ?? '')) !== ''
                            && preg_match('/^[A-Za-z]{2}$/', (string) ($result['state_code'] ?? '')) === 1
                            && preg_match('/^\d{5}(?:-\d{4})?$/', (string) ($result['postcode'] ?? '')) === 1;
                    })
                    ->map(function (array $result) use ($originLat, $originLon): array {
                        $km = static::distanceInKilometers(
                            (float) $originLat,
                            (float) $originLon,
                            $result['lat'] ?? null,
                            $result['lon'] ?? null,
                        );

                        return [
                            'address' => trim($result['housenumber'].' '.$result['street']),
                            'city' => (string) $result['city'],
                            'state' => strtoupper((string) $result['state_code']),
                            'zip_code' => (string) $result['postcode'],
                            'miles' => $km === null ? 0.0 : round($km * 0.621371, 1),
                        ];
                    })
                    ->unique(fn (array $c) => $c['address'].'|'.$c['city'].'|'.$c['zip_code'])
                    ->take($limit)
                    ->values()
                    ->all();
            } catch (GuzzleException $e) {
                Log::channel('submissions')->error('Geoapify nearby address lookup error', ApiErrorFormatter::format($e, [
                    'address' => $address,
                ]));

                return [];
            }
        });
    }

    /**
     * One geocoder call, accepted only if the answer is complete, confident
     * and consistent with what the sender told us.
     *
     * @param  array<string, string>  $query
     * @param  array{zip: ?string, state: ?string}  $anchor
     * @return array{address: string, city: string, state: string, zip_code: string}|null
     */
    private function geocodeQuery(array $query, string $address, array $anchor): ?array
    {
        try {
            $response = $this->httpClient->get('https://api.geoapify.com/v1/geocode/search', [
                'query' => $query + [
                    'apiKey' => $this->apiKey,
                    'format' => 'json',
                    'limit' => 1,
                    'filter' => 'countrycode:us',
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            $first = $data['results'][0] ?? null;

            if (! is_array($first)) {
                return null;
            }

            $zip = trim((string) ($first['postcode'] ?? ''));
            $city = trim((string) ($first['city'] ?? ''));
            $state = strtoupper(trim((string) ($first['state_code'] ?? '')));
            $houseNumber = trim((string) ($first['housenumber'] ?? ''));
            $street = trim((string) ($first['street'] ?? ''));
            $confidence = (float) ($first['rank']['confidence'] ?? 0);

            $complete = $houseNumber !== '' && $street !== '' && $city !== ''
                && preg_match('/^[A-Z]{2}$/', $state) === 1
                && preg_match('/^\d{5}(?:-\d{4})?$/', $zip) === 1;

            // The result has to agree with what the sender told us — otherwise
            // the geocoder resolved a different place entirely.
            // When the sender named a city, the answer must be that city — a
            // state match alone let "Barrington" resolve to "Port Barrington".
            $statedCity = self::statedCity($address);

            $agrees = $statedCity !== null
                ? str_contains(self::normalizeForCompare($statedCity), self::normalizeForCompare($city))
                    || str_contains(self::normalizeForCompare($city), self::normalizeForCompare($statedCity))
                : (($anchor['zip'] !== null && $anchor['zip'] === substr($zip, 0, 5))
                    || ($anchor['state'] !== null && $anchor['state'] === $state));

            // A partial match means it filled in the blanks itself — that is
            // exactly how the wrong ZIP gets through.
            if (! $complete || ! $agrees || $confidence < 0.9) {
                Log::channel('submissions')->info('Geoapify: address not resolved confidently enough to use', [
                    'address' => $address,
                    'structured' => ! isset($query['text']),
                    'resolved' => compact('city', 'state', 'zip'),
                    'confidence' => $confidence,
                    'complete' => $complete,
                    'agrees' => $agrees,
                ]);

                return null;
            }

            return [
                'address' => trim($houseNumber.' '.$street),
                'city' => $city,
                'state' => $state,
                'zip_code' => $zip,
            ];
        } catch (GuzzleException $e) {
            Log::channel('submissions')->error('Geoapify address lookup error', ApiErrorFormatter::format($e, [
                'address' => $address,
            ]));

            return null;
        }
    }

    /**
     * Break a written address into the fields the structured endpoint wants.
     * Null when there's no house number or no locality to go with it.
     *
     * @return array<string, string>|null
     */
    public static function splitForGeocoding(string $address): ?array
    {
        $address = self::normalizeSeparators($address);

        $segments = array_values(array_filter(array_map('trim', explode(',', $address))));

        if ($segments === []) {
            return null;
        }

        if (! preg_match('/^(\d+[A-Za-z]?)\s+(.+)$/', $segments[0], $m)) {
            return null;
        }

        $query = ['housenumber' => $m[1], 'street' => $m[2], 'country' => 'United States'];

        $tail = implode(' ', array_slice($segments, 1));

        if (preg_match('/\b(\d{5})(?:-\d{4})?\b/', $tail, $zipMatch)) {
            $query['postcode'] = $zipMatch[1];
            $tail = str_replace($zipMatch[0], '', $tail);
        }

        if (preg_match('/\b([A-Z]{2})\b(?![a-z])/', strtoupper($tail), $stateMatch)
            && in_array($stateMatch[1], self::US_STATE_CODES, true)) {
            $query['state'] = $stateMatch[1];
            $tail = preg_replace('/\b'.$stateMatch[1].'\b/i', '', $tail) ?? $tail;
        }

        $city = trim(preg_replace('/\b(USA|US|United States)\b/i', '', $tail) ?? '', " \t\n\r\0\x0B,");

        if ($city !== '') {
            $query['city'] = $city;
        }

        // Needs somewhere to look, not just a street.
        if (! isset($query['city']) && ! isset($query['postcode']) && ! isset($query['state'])) {
            return null;
        }

        return $query;
    }

    /** How far from the office we'll look for an unanchored address (50 miles). */
    private const SERVICE_RADIUS_METERS = 80000;

    /**
     * Put the commas back into an address typed without them.
     *
     * Everything here keys off comma-delimited segments, so "166 Akenside rd
     * Riverside Il" — a real lead that arrived from a partner site — looked
     * like a bare street and was refused, even though a human reads the city
     * and state right there in it. Leads take free text (no structured city /
     * state fields the way Client and Vendor forms do), so this arrives
     * regularly and cannot be validated away at the door.
     *
     * The repair only fires when there is no comma at all, and only when the
     * string ends in a state code AND a street suffix appears far enough
     * before it to leave a city in between. Both conditions matter, because a
     * trailing two-letter token is usually NOT a state:
     *
     *   "960 Danielson Ct"   — Ct is the street's own suffix, not Connecticut
     *   "123 Main St NE"     — NE is a directional, not Nebraska
     *
     * Neither has a city gap, so both stay untouched and stay refused. That
     * is the whole point of the anchor check and this must not weaken it.
     */
    public static function normalizeSeparators(string $address): string
    {
        $address = trim((string) preg_replace('/\s+/', ' ', $address));

        // A comma means the sender already said where the segments are.
        // Second-guessing that would break addresses that never needed help.
        if ($address === '' || str_contains($address, ',')) {
            return $address;
        }

        $tokens = explode(' ', $address);
        $stateIndex = count($tokens) - 1;

        // A ZIP trails the state: "... Riverside IL 60546".
        $zip = null;
        if (preg_match('/^\d{5}(?:-\d{4})?$/', $tokens[$stateIndex]) === 1) {
            $zip = $tokens[$stateIndex];
            $stateIndex--;
        }

        if ($stateIndex < 0) {
            return $address;
        }

        $state = strtoupper($tokens[$stateIndex]);

        if (! in_array($state, self::US_STATE_CODES, true)) {
            return $address;
        }

        // The last street suffix leaving at least one word before the state —
        // that gap is the city. Start at $stateIndex - 2 so the gap exists by
        // construction, and stop at 1 so the house number is never the suffix.
        $suffixIndex = null;
        for ($i = $stateIndex - 2; $i >= 1; $i--) {
            if (in_array(strtoupper(rtrim($tokens[$i], '.')), self::STREET_SUFFIXES, true)) {
                $suffixIndex = $i;
                break;
            }
        }

        if ($suffixIndex === null) {
            return $address;
        }

        $street = implode(' ', array_slice($tokens, 0, $suffixIndex + 1));
        $city = implode(' ', array_slice($tokens, $suffixIndex + 1, $stateIndex - $suffixIndex - 1));

        return $street.', '.$city.', '.$state.($zip !== null ? ' '.$zip : '');
    }

    /**
     * What in this address pins it to a place: a ZIP, a state, or a trailing
     * city segment. Null when it is only a street.
     *
     * @return array{zip: ?string, state: ?string}|null
     */
    public static function addressAnchor(string $address): ?array
    {
        $address = self::normalizeSeparators($address);

        $segments = array_values(array_filter(array_map('trim', explode(',', $address))));

        // Scan for a state ONLY past the street: street suffixes are valid
        // state codes ("960 Danielson Ct" is not Connecticut, "5 Oak St" is not
        // a state at all), and every two-letter token has to be considered —
        // testing just the first one missed the IL in "5 Oak St, Barrington, IL".
        $tail = implode(' ', array_slice($segments, 1));

        $state = null;
        if (preg_match_all('/\b([A-Za-z]{2})\b/', $tail, $matches)) {
            foreach ($matches[1] as $candidate) {
                $candidate = strtoupper($candidate);
                if (in_array($candidate, self::US_STATE_CODES, true)) {
                    $state = $candidate;
                    break;
                }
            }
        }

        $zip = preg_match('/\b(\d{5})(?:-\d{4})?\b/', $address, $m) ? $m[1] : null;

        // "960 Danielson Ct, Gurnee" — a segment after the street counts.
        $hasCitySegment = count($segments) > 1 && $segments[1] !== '';

        if ($zip === null && $state === null && ! $hasCitySegment) {
            return null;
        }

        return ['zip' => $zip, 'state' => $state];
    }

    /**
     * Cache an answer for a month, a non-answer for minutes.
     *
     * @template T
     *
     * @param  callable(): T  $resolve
     * @return T
     */
    private function rememberResolved(string $key, callable $resolve): mixed
    {
        $cached = Cache::get($key);

        if ($cached !== null && $cached !== []) {
            return $cached;
        }

        $value = $resolve();

        Cache::put(
            $key,
            $value,
            $value === null || $value === [] ? now()->addMinutes(15) : now()->addDays(30),
        );

        return $value;
    }

    /**
     * "lon,lat" of the business's home base, used to anchor an unanchored
     * geocode search to the service area and to rank nearby-address
     * candidates. hive resolves this per-vendor (it's multi-tenant); gsc is
     * one business, so it's fixed to Prospect Heights, IL — the same
     * coordinates already published as this business's `geo` in
     * resources/views/components/schema-org.blade.php.
     */
    private function officeLocation(): ?string
    {
        return self::OFFICE_LOCATION;
    }

    private const OFFICE_LOCATION = '-87.9376,42.0953';

    /** The city segment the sender wrote, if any. */
    private static function statedCity(string $address): ?string
    {
        $parts = self::splitForGeocoding($address);

        return $parts['city'] ?? null;
    }

    private static function normalizeForCompare(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
    }

    /**
     * Street suffixes, used only to find where a comma-less street ends and
     * its city begins (normalizeSeparators). Deliberately spelled out rather
     * than pattern-matched: the whole safety of that repair rests on this
     * being a closed list.
     *
     * @var list<string>
     */
    private const STREET_SUFFIXES = [
        'ST', 'STREET', 'RD', 'ROAD', 'AVE', 'AV', 'AVENUE', 'DR', 'DRIVE',
        'LN', 'LANE', 'CT', 'COURT', 'BLVD', 'BOULEVARD', 'WAY', 'PL', 'PLACE',
        'TER', 'TERR', 'TERRACE', 'CIR', 'CIRCLE', 'PKWY', 'PARKWAY', 'HWY',
        'HIGHWAY', 'TRL', 'TRAIL', 'SQ', 'SQUARE', 'PLZ', 'PLAZA', 'LOOP',
        'RUN', 'PATH', 'PIKE', 'ROW', 'WALK', 'XING', 'CROSSING',
    ];

    /** @var list<string> */
    private const US_STATE_CODES = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA',
        'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
        'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT',
        'VA', 'WA', 'WV', 'WI', 'WY', 'DC',
    ];

    /** @param array<int, array<string, mixed>> $results */
    public static function sortResultsByDistance(array $results, ?string $location): array
    {
        if ($location === null) {
            return $results;
        }

        [$originLon, $originLat] = array_pad(explode(',', $location), 2, null);

        if (! is_numeric($originLon) || ! is_numeric($originLat)) {
            return $results;
        }

        return collect($results)
            ->sortBy(function (array $result) use ($originLon, $originLat): float {
                $distance = static::distanceInKilometers(
                    (float) $originLat,
                    (float) $originLon,
                    $result['lat'] ?? null,
                    $result['lon'] ?? null
                );

                return $distance ?? INF;
            })
            ->values()
            ->all();
    }

    protected static function distanceInKilometers(float $fromLat, float $fromLon, mixed $toLat, mixed $toLon): ?float
    {
        if (! is_numeric($toLat) || ! is_numeric($toLon)) {
            return null;
        }

        $earthRadiusKm = 6371.0;
        $lat1 = deg2rad($fromLat);
        $lat2 = deg2rad((float) $toLat);
        $deltaLat = deg2rad((float) $toLat - $fromLat);
        $deltaLon = deg2rad((float) $toLon - $fromLon);

        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
