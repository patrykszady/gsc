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

        // Minimal API call to verify access token works. Asked again after a
        // passing Google failure: one "503 UNAVAILABLE" on this call was a
        // failed health check, and the grant it is here to verify was fine.
        $result = $service->withTransientRetry(fn (): ?array => $service->listMedia(null, 1));

        if ($result === null) {
            $error = $service->getLastError();
            $status = (int) ($error['status'] ?? 0);
            $message = 'GBP health check failed: unable to list media'
                .($status > 0 ? " (HTTP {$status})" : '')
                .(($error['description'] ?? '') !== '' ? ' — '.$error['description'] : '.');
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
