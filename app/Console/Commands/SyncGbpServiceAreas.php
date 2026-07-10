<?php

namespace App\Console\Commands;

use App\Services\GoogleBusinessProfileService;
use Illuminate\Console\Command;

/**
 * Push the curated service areas in config/gbp-services.php to the live Google
 * Business Profile. Purpose-built to run on PRODUCTION, where the GBP OAuth
 * token is valid (local tokens are invalid_grant).
 *
 * Place IDs are resolved via the Places API (New) Text Search — the classic
 * Geocoding API is not enabled on our Cloud project. Run with --dry-run first
 * to preview the resolution without touching the profile.
 *
 *   php artisan gbp:sync-service-areas --dry-run
 *   php artisan gbp:sync-service-areas
 */
class SyncGbpServiceAreas extends Command
{
    protected $signature = 'gbp:sync-service-areas
        {--dry-run : Resolve + preview every area without writing to GBP}
        {--business-type= : CUSTOMER_LOCATION_ONLY | CUSTOMER_AND_BUSINESS_LOCATION (default: keep current)}';

    protected $description = 'Sync config/gbp-services.php service areas to the live Google Business Profile.';

    public function handle(GoogleBusinessProfileService $gbp): int
    {
        $areas = array_values(array_filter((array) config('gbp-services.service_areas', [])));

        if (empty($areas)) {
            $this->error('No service areas in config/gbp-services.php.');

            return self::FAILURE;
        }

        if (count($areas) > 20) {
            $this->warn('Google allows max 20 service areas; only the first 20 will apply.');
        }

        if (! $gbp->isConfigured()) {
            $this->error('GBP is not configured/authorized in this environment. Run this on production.');

            return self::FAILURE;
        }

        // Resolve every area to a Place ID and show the result.
        $this->info('Resolving ' . count($areas) . ' service areas via Places API…');
        $resolved = [];
        $failed = [];

        foreach ($areas as $name) {
            $placeId = $gbp->resolveServiceAreaPlaceId($name);
            if ($placeId) {
                $resolved[] = $name;
                $this->line(sprintf('  ✓ %-26s %s', str_replace(', USA', '', $name), $placeId));
            } else {
                $failed[] = $name;
                $this->line(sprintf('  ✗ %-26s FAILED', str_replace(', USA', '', $name)));
            }
        }

        if ($failed) {
            $this->warn(count($failed) . ' could not be resolved: ' . implode(', ', $failed));
        }

        if (empty($resolved)) {
            $this->error('Nothing resolved — aborting (profile unchanged).');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->comment("Dry run — GBP not modified. {$this->pluralize(count($resolved))} would be set.");

            return self::SUCCESS;
        }

        $this->info('Writing ' . count($resolved) . ' service areas to the live profile…');
        $result = $gbp->updateServiceArea($resolved, $this->option('business-type') ?: null);

        if ($result === null) {
            $this->error('GBP update failed: ' . json_encode($gbp->getLastError()));

            return self::FAILURE;
        }

        $this->info('✅ Service areas updated — ' . count($resolved) . ' areas are now live on the profile.');

        return self::SUCCESS;
    }

    private function pluralize(int $n): string
    {
        return $n . ' area' . ($n === 1 ? '' : 's');
    }
}
