<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class SeoSyncZipCities extends Command
{
    protected $signature = 'seo:sync-zip-cities
        {--zip= : Restrict to one ZIP code}
        {--limit=0 : Max ZIPs to resolve (0 = all)}
        {--force : Re-resolve ZIPs that already have a city}';

    protected $description = 'Resolve and persist ZIP -> city mapping for service-area ZIP pages';

    public function handle(): int
    {
        $zips = app(\App\Services\HiveProjectsClient::class)->storedZips();
        // Filter to 5-digit only for consistency
        $zips = array_values(array_filter($zips, fn ($z) => strlen((string) $z) === 5 && ctype_digit((string) $z)));
        if (empty($zips)) {
            $this->error('No ZIPs found in hive_project_zip_counts. Run `php artisan hive:sync` first.');
            return self::FAILURE;
        }

        $targetZip = preg_replace('/\D/', '', (string) $this->option('zip'));
        if ($targetZip !== '') {
            $zips = array_values(array_filter($zips, fn (string $z) => $z === $targetZip));
            if (empty($zips)) {
                $this->error("ZIP {$targetZip} not found in CSV.");
                return self::FAILURE;
            }
        }

        $force = (bool) $this->option('force');
        $limit = max(0, (int) $this->option('limit'));

        $path = 'seo/zip-city-map.json';
        $existing = [];
        if (Storage::disk('local')->exists($path)) {
            $decoded = json_decode((string) Storage::disk('local')->get($path), true);
            if (is_array($decoded)) {
                foreach ($decoded as $zip => $city) {
                    $zip = preg_replace('/\D/', '', (string) $zip);
                    $city = trim((string) $city);
                    if (strlen((string) $zip) === 5 && $city !== '') {
                        $existing[$zip] = $city;
                    }
                }
            }
        }

        $queue = [];
        foreach ($zips as $zip) {
            if (! $force && isset($existing[$zip])) {
                continue;
            }
            $queue[] = $zip;
        }

        if ($limit > 0) {
            $queue = array_slice($queue, 0, $limit);
        }

        if (empty($queue)) {
            $this->info('Nothing to resolve. Use --force to refresh existing ZIP mappings.');
            return self::SUCCESS;
        }

        $this->info('Resolving city for ' . count($queue) . ' ZIP(s)...');

        $ok = 0;
        $failed = 0;
        $updated = $existing;

        foreach ($queue as $zip) {
            $city = $this->resolveCityForZip($zip);
            if ($city === null) {
                $failed++;
                $this->warn("- {$zip}: failed");
                continue;
            }

            $updated[$zip] = $city;
            $ok++;
            $this->line("- {$zip}: {$city}");

            // Be polite with public API.
            usleep(150000);
        }

        ksort($updated);
        Storage::disk('local')->put(
            $path,
            json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->newLine();
        $this->info("Done. resolved={$ok}, failed={$failed}, total_saved=" . count($updated));

        return self::SUCCESS;
    }

    protected function resolveCityForZip(string $zip): ?string
    {
        try {
            $response = Http::timeout(12)
                ->acceptJson()
                ->get("https://api.zippopotam.us/us/{$zip}");

            if (! $response->ok()) {
                return null;
            }

            $json = $response->json();
            $place = is_array($json) ? ($json['places'][0] ?? null) : null;
            $city = is_array($place) ? trim((string) ($place['place name'] ?? '')) : '';

            return $city !== '' ? $city : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
