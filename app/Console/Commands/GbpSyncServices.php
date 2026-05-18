<?php

namespace App\Console\Commands;

use App\Services\GoogleBusinessProfileService;
use Illuminate\Console\Command;

/**
 * Pushes the curated service list (config/gbp-services.php) to the GBP
 * listing as "free-form" service items. Google replaces — it does not merge —
 * so the command always sends the full list.
 *
 * Run on-demand whenever the catalog changes:
 *   php artisan gbp:services-sync --dry-run
 *   php artisan gbp:services-sync
 */
class GbpSyncServices extends Command
{
    protected $signature = 'gbp:services-sync {--dry-run : Preview the payload without writing}';

    protected $description = 'Sync the curated service list from config/gbp-services.php to Google Business Profile.';

    public function handle(GoogleBusinessProfileService $service): int
    {
        $items = config('gbp-services.services', []);

        if (empty($items)) {
            $this->error('No services defined in config/gbp-services.php.');
            return self::FAILURE;
        }

        if (! $service->isConfigured()) {
            $this->error('Google Business Profile is not configured.');
            return self::FAILURE;
        }

        $this->info('GBP service items to push: ' . count($items));
        foreach ($items as $i => $item) {
            $this->line(sprintf('  %2d. %s', $i + 1, $item['name']));
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('Dry run — no changes pushed to Google.');
            return self::SUCCESS;
        }

        $result = $service->updateServiceItems($items);

        if ($result === null) {
            $err = $service->getLastError();
            $this->error('Update failed: ' . ($err['message'] ?? 'unknown'));
            $this->line(json_encode($err, JSON_PRETTY_PRINT));
            return self::FAILURE;
        }

        $this->info('✓ Service items synced to Google Business Profile.');
        return self::SUCCESS;
    }
}
