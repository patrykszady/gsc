<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Services\GoogleBusinessProfileService;
use Illuminate\Console\Command;

class UpdateGbpProfile extends Command
{
    protected $signature = 'google-business-profile:update-profile
        {--service-areas : Update the service areas (top 20 cities)}
        {--categories : Update the business categories}
        {--show : Show current location details without making changes}
        {--all : Update both service areas and categories}
        {--dry-run : Show what would be sent without making API calls}';

    protected $description = 'Update GBP profile: service areas and business categories.';

    /**
     * Office coordinates (Prospect Heights, IL 60070). Service areas are chosen
     * by physical proximity to this point — Google caps service areas at 20 and
     * ranks service-area businesses primarily by distance.
     */
    protected const OFFICE_LAT = 42.0953;

    protected const OFFICE_LNG = -87.9376;

    /**
     * Google's hard limit on the number of service areas per location.
     */
    protected const MAX_SERVICE_AREAS = 20;

    /**
     * GBP category IDs (gcid format).
     */
    protected const PRIMARY_CATEGORY = 'gcid:remodeler';

    protected const ADDITIONAL_CATEGORIES = [
        'gcid:kitchen_remodeler',
        'gcid:bathroom_remodeler',
        'gcid:general_contractor',
    ];

    public function handle(GoogleBusinessProfileService $service): int
    {
        if (! $service->isConfigured()) {
            $this->error('Google Business Profile is not fully configured.');

            return self::FAILURE;
        }

        $doServiceAreas = $this->option('service-areas') || $this->option('all');
        $doCategories = $this->option('categories') || $this->option('all');
        $showOnly = $this->option('show');
        $dryRun = $this->option('dry-run');

        if (! $doServiceAreas && ! $doCategories && ! $showOnly) {
            $this->warn('No action specified. Use --service-areas, --categories, --all, or --show.');

            return self::SUCCESS;
        }

        // Show current location details
        if ($showOnly) {
            return $this->showLocation($service);
        }

        $exitCode = self::SUCCESS;

        if ($doServiceAreas) {
            $result = $this->updateServiceAreas($service, $dryRun);
            if ($result !== self::SUCCESS) {
                $exitCode = $result;
            }
        }

        if ($doCategories) {
            $result = $this->updateCategories($service, $dryRun);
            if ($result !== self::SUCCESS) {
                $exitCode = $result;
            }
        }

        return $exitCode;
    }

    protected function showLocation(GoogleBusinessProfileService $service): int
    {
        $this->info('Fetching current location details...');

        $location = $service->getLocation('name,title,categories,serviceArea,storefrontAddress,websiteUri');

        if (! $location) {
            $error = $service->getLastError();
            $this->error('Failed to fetch location: ' . ($error['message'] ?? 'Unknown error'));
            if (isset($error['body'])) {
                $this->line($error['body']);
            }

            return self::FAILURE;
        }

        $this->info('Location: ' . ($location['title'] ?? 'N/A'));
        $this->info('Website: ' . ($location['websiteUri'] ?? 'N/A'));

        // Storefront Address
        $address = $location['storefrontAddress'] ?? null;
        if ($address) {
            $lines = array_filter([
                implode(', ', array_filter($address['addressLines'] ?? [])),
                trim(($address['locality'] ?? '') . ', ' . ($address['administrativeArea'] ?? '') . ' ' . ($address['postalCode'] ?? '')),
            ]);
            $this->info('Storefront Address: ' . implode(', ', $lines));
        } else {
            $this->info('Storefront Address: (none)');
        }

        // Categories
        $categories = $location['categories'] ?? [];
        $primary = $categories['primaryCategory']['displayName'] ?? 'N/A';
        $this->info("Primary Category: {$primary}");

        $additional = $categories['additionalCategories'] ?? [];
        if ($additional) {
            $this->info('Additional Categories:');
            foreach ($additional as $cat) {
                $this->line('  - ' . ($cat['displayName'] ?? $cat['name'] ?? 'Unknown'));
            }
        }

        // Service Area
        $serviceArea = $location['serviceArea'] ?? [];
        $businessType = $serviceArea['businessType'] ?? 'N/A';
        $this->info("Service Area Type: {$businessType}");

        $places = $serviceArea['places']['placeInfos'] ?? [];
        if ($places) {
            $this->info('Service Areas (' . count($places) . '):');
            foreach ($places as $place) {
                $this->line('  - ' . ($place['placeName'] ?? 'Unknown'));
            }
        } else {
            $this->warn('No service areas configured.');
        }

        return self::SUCCESS;
    }

    protected function updateServiceAreas(GoogleBusinessProfileService $service, bool $dryRun): int
    {
        // Prefer the curated list (counties + key cities) from config; fall back
        // to the 20 cities physically closest to the office when it's empty.
        $configured = array_values(array_filter(array_map(
            'trim',
            (array) config('gbp-services.service_areas', [])
        )));

        if (! empty($configured)) {
            $cities = array_slice($configured, 0, self::MAX_SERVICE_AREAS);
            $source = 'config/gbp-services.php';
        } else {
            $cities = $this->closestCities(self::MAX_SERVICE_AREAS)
                ->map(fn (AreaServed $a) => "{$a->city}, IL, USA")
                ->all();
            $source = 'closest to office';
        }

        if (empty($cities)) {
            $this->error('No service areas available. Set config gbp-services.service_areas or run `php artisan gbp:geocode-areas`.');

            return self::FAILURE;
        }

        $this->info('Service areas to set (' . count($cities) . ", {$source}):");
        foreach ($cities as $i => $city) {
            $this->line('  ' . ($i + 1) . '. ' . $city);
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] Would update service areas with the above cities.');

            return self::SUCCESS;
        }

        $this->info('Resolving Google Place IDs and updating GBP service areas...');

        $result = $service->updateServiceArea($cities);

        if (! $result) {
            $error = $service->getLastError();
            $this->error('Failed to update service areas: ' . ($error['message'] ?? 'Unknown error'));
            if (isset($error['body'])) {
                $this->line($error['body']);
            }

            return self::FAILURE;
        }

        $this->info('Service areas updated successfully (' . count($cities) . ' cities).');

        return self::SUCCESS;
    }

    /**
     * The N AreaServed cities physically closest to the office, ordered by
     * Haversine distance. Areas without coordinates are skipped.
     *
     * @return \Illuminate\Support\Collection<int, AreaServed>
     */
    protected function closestCities(int $limit): \Illuminate\Support\Collection
    {
        $haversine = '(3959 * acos(cos(radians(?)) * cos(radians(latitude)) '
            . '* cos(radians(longitude) - radians(?)) '
            . '+ sin(radians(?)) * sin(radians(latitude))))';

        return AreaServed::query()
            ->select('*')
            ->selectRaw("{$haversine} AS distance_miles", [self::OFFICE_LAT, self::OFFICE_LNG, self::OFFICE_LAT])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('distance_miles')
            ->limit($limit)
            ->get();
    }

    protected function updateCategories(GoogleBusinessProfileService $service, bool $dryRun): int
    {
        $primaryCategory = (string) (config('gbp-services.categories.primary') ?: self::PRIMARY_CATEGORY);
        $additionalCategories = array_values(array_filter(
            (array) config('gbp-services.categories.additional', self::ADDITIONAL_CATEGORIES),
            fn ($value) => is_string($value) && $value !== ''
        ));

        $this->info('Categories to set:');
        $this->line('  Primary: ' . $primaryCategory);
        foreach ($additionalCategories as $cat) {
            $this->line('  Additional: ' . $cat);
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] Would update categories with the above.');

            return self::SUCCESS;
        }

        $this->info('Updating GBP categories...');

        $result = $service->updateCategories($primaryCategory, $additionalCategories);

        if (! $result) {
            $error = $service->getLastError();
            $this->error('Failed to update categories: ' . ($error['message'] ?? 'Unknown error'));
            if (isset($error['body'])) {
                $this->line($error['body']);
            }

            return self::FAILURE;
        }

        $this->info('Categories updated successfully.');

        return self::SUCCESS;
    }
}
