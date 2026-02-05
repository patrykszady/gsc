<?php

namespace App\Console\Commands;

use App\Services\GoogleBusinessProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ListGoogleBusinessProfileLocations extends Command
{
    protected $signature = 'google-business-profile:locations {accountId? : Account ID (e.g., 123456789)}';

    protected $description = 'List Google Business Profile account and location IDs for configuration.';

    public function handle(GoogleBusinessProfileService $service): int
    {
        if (! $service->hasOAuthCredentials()) {
            $this->error('Missing OAuth client credentials or refresh token.');
            return self::FAILURE;
        }

        $accountId = $this->argument('accountId');

        if (! $accountId) {
            $accounts = $service->listAccounts();

            if (empty($accounts)) {
                $this->renderErrorDetails($service->getLastError());
                $this->error('No accounts found or access denied.');
                return self::FAILURE;
            }

            $rows = collect($accounts)->map(function ($account) {
                $name = $account['name'] ?? '';
                $id = $this->extractId($name);

                return [
                    'account_id' => $id ?: $name,
                    'name' => $account['accountName'] ?? '',
                    'type' => $account['type'] ?? '',
                ];
            })->all();

            $this->info('Accounts:');
            $this->table(['account_id', 'name', 'type'], $rows);

            $accountId = $rows[0]['account_id'] ?? null;
            if (! $accountId) {
                return self::SUCCESS;
            }

            $this->newLine();
            $this->info('Using first account. Provide accountId to select a different one.');
        }

        $locations = $service->listLocations($accountId);

        if (empty($locations)) {
            $this->renderErrorDetails($service->getLastError());
            $this->error('No locations found or access denied.');
            return self::FAILURE;
        }

        $rows = collect($locations)->map(function ($location) {
            $name = $location['name'] ?? '';
            $locationId = $this->extractId($name);

            return [
                'location_id' => $locationId ?: $name,
                'title' => $location['title'] ?? '',
                'location_name' => $location['storeCode'] ?? '',
                'website_url' => $location['websiteUri'] ?? '',
            ];
        })->all();

        $this->info('Locations:');
        $this->table(['location_id', 'title', 'location_name', 'website_url'], $rows);

        return self::SUCCESS;
    }

    protected function renderErrorDetails(?array $error): void
    {
        if (! $error) {
            return;
        }

        $this->newLine();
        $this->line('Details:');
        foreach ($error as $key => $value) {
            $this->line("- {$key}: {$value}");
        }
        $this->newLine();
    }

    protected function extractId(string $resourceName): ?string
    {
        if (! $resourceName) {
            return null;
        }

        $parts = explode('/', $resourceName);

        return Str::of(end($parts))->trim()->toString();
    }
}
