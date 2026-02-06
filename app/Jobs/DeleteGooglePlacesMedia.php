<?php

namespace App\Jobs;

use App\Services\GoogleBusinessProfileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteGooglePlacesMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public string $mediaName
    ) {}

    public function handle(GoogleBusinessProfileService $service): void
    {
        if (! $service->isConfigured()) {
            return;
        }

        $deleted = $service->deleteMedia($this->mediaName);

        if (! $deleted) {
            $error = $service->getLastError();
            Log::warning('GBP: Delete job failed', [
                'media_name' => $this->mediaName,
                'error' => $error,
            ]);

            // Retry on server errors
            $status = $error['status'] ?? 0;
            if ($status >= 500) {
                $this->release(30);
            }
        }
    }
}
