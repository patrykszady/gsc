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
     * If the API rejects all areas (max 20), fall back to the 20 closest
     * cities to Prospect Heights by distance.
     */
    protected const FALLBACK_CITIES = [
        'Prospect Heights',
        'Mount Prospect',
        'Arlington Heights',
        'Wheeling',
        'Buffalo Grove',
        'Rolling Meadows',
        'Palatine',
        'Des Plaines',
        'Riverwoods',
        'Northbrook',
        'Elk Grove Village',
        'Long Grove',
        'Deerfield',
        'Lincolnshire',
        'Park Ridge',
        'Kildeer',
        'Rosemont',
        'Glenview',
        'Hoffman Estates',
        'Inverness',
    ];

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

        $location = $service->getLocation('name,title,categories,serviceArea,websiteUri');

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
        // Use all areas served from the database
        $allCities = AreaServed::orderBy('city')->pluck('city')
            ->map(fn (string $city) => "{$city}, IL, USA")
            ->toArray();

        $cities = $allCities;

        $this->info('Service areas to set (' . count($cities) . '):');
        foreach ($cities as $i => $city) {
            $this->line('  ' . ($i + 1) . '. ' . $city);
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] Would update service areas with the above cities.');

            return self::SUCCESS;
        }

        $this->info('Updating GBP service areas...');

        $result = $service->updateServiceArea($cities);

        // If all cities failed (likely 20-area limit), retry with fallback list
        if (! $result && count($cities) > 20) {
            $this->warn('Failed with all ' . count($cities) . ' cities. Retrying with 20 closest to Prospect Heights...');

            $cities = collect(self::FALLBACK_CITIES)
                ->map(fn (string $city) => "{$city}, IL, USA")
                ->toArray();

            $this->info('Fallback service areas (' . count($cities) . '):');
            foreach ($cities as $i => $city) {
                $this->line('  ' . ($i + 1) . '. ' . $city);
            }

            $result = $service->updateServiceArea($cities);
        }

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

    protected function updateCategories(GoogleBusinessProfileService $service, bool $dryRun): int
    {
        $this->info('Categories to set:');
        $this->line('  Primary: ' . self::PRIMARY_CATEGORY);
        foreach (self::ADDITIONAL_CATEGORIES as $cat) {
            $this->line('  Additional: ' . $cat);
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] Would update categories with the above.');

            return self::SUCCESS;
        }

        $this->info('Updating GBP categories...');

        $result = $service->updateCategories(self::PRIMARY_CATEGORY, self::ADDITIONAL_CATEGORIES);

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
