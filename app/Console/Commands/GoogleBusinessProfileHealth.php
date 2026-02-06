<?php

namespace App\Console\Commands;

use App\Services\GoogleBusinessProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GoogleBusinessProfileHealth extends Command
{
    protected $signature = 'google-business-profile:health';

    protected $description = 'Verify Google Business Profile credentials and API access.';

    public function handle(GoogleBusinessProfileService $service): int
    {
        if (! $service->isConfigured()) {
            $message = 'GBP health check failed: configuration is incomplete.';
            $this->error($message);
            Log::error($message);

            return self::FAILURE;
        }

        // Minimal API call to verify access token works.
        $result = $service->listMedia(null, 1);

        if ($result === null) {
            $error = $service->getLastError();
            $message = 'GBP health check failed: unable to list media.';
            $this->error($message);
            Log::error($message, [
                'error' => $error,
            ]);

            return self::FAILURE;
        }

        $this->info('GBP health check OK.');

        return self::SUCCESS;
    }
}
