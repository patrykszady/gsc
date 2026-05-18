<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Backfill latitude/longitude on `areas_served` rows using the Google
 * Geocoding API. Required for GBP photo EXIF geotagging to actually fire
 * (uploads without matching coords go up un-tagged).
 *
 *   php artisan gbp:geocode-areas              # only rows missing coords
 *   php artisan gbp:geocode-areas --force      # re-geocode everything
 *   php artisan gbp:geocode-areas --dry-run    # show results without saving
 *
 * Uses GOOGLE_PLACES_API_KEY. Geocoding API must be enabled on that key.
 */
class GbpGeocodeAreas extends Command
{
    protected $signature = 'gbp:geocode-areas
        {--force : Re-geocode rows that already have coords}
        {--dry-run : Print results without saving}
        {--state=IL : Append to the geocode query for disambiguation}
        {--country=USA : Append to the geocode query}
        {--provider=auto : auto|google|nominatim. "auto" tries Google then falls back to Nominatim.}
        {--sleep= : Milliseconds between API calls (default: 120 for Google, 1100 for Nominatim)}';

    protected $description = 'Backfill latitude/longitude on areas_served rows via the Google Geocoding API.';

    public function handle(): int
    {
        $apiKey = config('services.google.places_api_key');
        $provider = $this->option('provider');

        if ($provider === 'google' && ! $apiKey) {
            $this->error('GOOGLE_PLACES_API_KEY is not configured.');
            return self::FAILURE;
        }

        $query = AreaServed::query()->orderBy('city');
        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('latitude')->orWhereNull('longitude');
            });
        }

        $areas = $query->get();
        $total = $areas->count();
        if ($total === 0) {
            $this->info('No areas to geocode.');
            return self::SUCCESS;
        }

        $state = $this->option('state');
        $country = $this->option('country');
        $dryRun = (bool) $this->option('dry-run');

        // Resolve active provider (auto = google first, then nominatim).
        $activeProvider = $provider === 'auto'
            ? ($apiKey ? 'google' : 'nominatim')
            : $provider;

        $sleepMs = $this->option('sleep') !== null
            ? (int) $this->option('sleep')
            : ($activeProvider === 'nominatim' ? 1100 : 120);

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Geocoding {$total} area(s) via {$activeProvider}…");

        $ok = 0;
        $failed = 0;
        $progress = $this->output->createProgressBar($total);
        $progress->start();

        foreach ($areas as $area) {
            $address = trim(
                $area->city
                . ($state ? ", {$state}" : '')
                . ($country ? ", {$country}" : '')
            );

            $coords = $this->geocodeVia($activeProvider, $address, $apiKey);

            // auto-fallback Google -> Nominatim on REQUEST_DENIED / not-enabled.
            if ($coords === null && $provider === 'auto' && $activeProvider === 'google'
                && $this->lastError !== null
                && (str_contains($this->lastError, 'REQUEST_DENIED') || str_contains($this->lastError, 'not activated'))) {
                $this->newLine();
                $this->warn('Google Geocoding API rejected the key — falling back to OpenStreetMap Nominatim for remaining areas.');
                $activeProvider = 'nominatim';
                $sleepMs = max($sleepMs, 1100);
                $coords = $this->geocodeVia($activeProvider, $address, $apiKey);
            }

            if ($coords === null) {
                $failed++;
                $progress->advance();
                continue;
            }

            [$lat, $lng] = $coords;

            if (! $dryRun) {
                $area->forceFill([
                    'latitude' => $lat,
                    'longitude' => $lng,
                ])->save();
            }

            $ok++;
            $progress->advance();

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $progress->finish();
        $this->newLine(2);

        $this->info("✓ Geocoded: {$ok}");
        if ($failed > 0) {
            $this->warn("✗ Failed:    {$failed}");
        }

        if ($dryRun) {
            $this->warn('Dry run — no rows updated.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    protected function geocodeVia(string $provider, string $address, ?string $apiKey): ?array
    {
        return $provider === 'nominatim'
            ? $this->geocodeNominatim($address)
            : $this->geocode($address, (string) $apiKey);
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    protected function geocodeNominatim(string $address): ?array
    {
        try {
            // Nominatim usage policy requires a real User-Agent identifying the app.
            $response = Http::withHeaders([
                    'User-Agent' => 'GS-Construction-GBP-Geotag/1.0 (' . config('app.url') . ')',
                    'Accept' => 'application/json',
                ])
                ->timeout(15)
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $address,
                    'format' => 'json',
                    'limit' => 1,
                    'addressdetails' => 0,
                ]);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }

        if (! $response->successful()) {
            $this->lastError = 'HTTP ' . $response->status();
            return null;
        }

        $first = $response->json(0);
        if (! is_array($first) || ! isset($first['lat'], $first['lon'])) {
            $this->lastError = 'no results';
            return null;
        }

        $this->lastError = null;
        return [(float) $first['lat'], (float) $first['lon']];
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    protected function geocode(string $address, string $apiKey): ?array
    {
        try {
            $response = Http::timeout(10)
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => $address,
                    'key' => $apiKey,
                ]);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }

        if (! $response->successful()) {
            $this->lastError = 'HTTP ' . $response->status();
            return null;
        }

        $payload = $response->json();
        $status = $payload['status'] ?? 'UNKNOWN';

        if ($status !== 'OK') {
            $msg = $payload['error_message'] ?? '';
            $this->lastError = trim("{$status} {$msg}");

            // Surface fatal config errors once and bail out fast.
            if (in_array($status, ['REQUEST_DENIED', 'INVALID_REQUEST', 'OVER_QUERY_LIMIT'], true) && ! $this->fatalShown) {
                $this->fatalShown = true;
                $this->newLine();
                $this->error("Geocoding API error: {$this->lastError}");
                if (str_contains($msg, 'not activated')) {
                    $this->line('Enable it here: https://console.cloud.google.com/apis/library/geocoding-backend.googleapis.com');
                }
            }
            return null;
        }

        $loc = $payload['results'][0]['geometry']['location'] ?? null;
        if (! is_array($loc) || ! isset($loc['lat'], $loc['lng'])) {
            $this->lastError = 'no results';
            return null;
        }

        $this->lastError = null;
        return [(float) $loc['lat'], (float) $loc['lng']];
    }

    protected ?string $lastError = null;
    protected bool $fatalShown = false;
}
