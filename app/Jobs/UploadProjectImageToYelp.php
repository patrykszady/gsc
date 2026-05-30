<?php

namespace App\Jobs;

use App\Exceptions\YelpUploadThrottledException;
use App\Models\ProjectImage;
use App\Services\YelpBusinessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UploadProjectImageToYelp implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 100;
    public int $timeout = 300;
    public int $uniqueFor = 7200;

    public function retryUntil(): \DateTime
    {
        return now()->addHours(12)->toDateTime();
    }

    public function __construct(
        public int $imageId,
        public bool $forceRefresh = false,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->imageId;
    }

    // Sequencing is enforced by the media-sync Horizon supervisor running with
    // --max-processes=1 --balance=simple. We intentionally do NOT use
    // WithoutOverlapping here — releasing-with-delay would push jobs into the
    // delayed ZSET and make pending uploads hard to wipe in an emergency.
    // With one worker, pending jobs sit in the plain queues:media-sync LIST
    // and `redis-cli DEL queues:media-sync` clears them instantly.

    public function handle(YelpBusinessService $service): void
    {
        try {
            $image = ProjectImage::with('project')->find($this->imageId);

            if (! $image) {
                Log::channel('yelp')->warning('Yelp: image not found', ['image_id' => $this->imageId]);
                return;
            }

            $project = $image->project;
            if (! $project || ! $project->is_published) {
                return;
            }

            if ($image->yelp_uploaded_at && ! $this->forceRefresh) {
                return;
            }

            if (! $service->isConfigured()) {
                Log::channel('yelp')->info('Yelp: service not configured, skipping');
                return;
            }

            if (empty($project->yelp_portfolio_url)) {
                Log::channel('yelp')->info('Yelp: project has no yelp_portfolio_url, skipping', [
                    'project_id' => $project->id,
                ]);
                return;
            }

            $photoId = null;
            try {
                $photoId = $service->uploadProjectImage($image);
            } catch (YelpUploadThrottledException $e) {
                Log::channel('yelp')->info('Yelp: throttled, releasing portfolio job', [
                    'image_id' => $this->imageId,
                    'retry_after_seconds' => $e->retryAfterSeconds,
                ]);
                $this->release($e->retryAfterSeconds);
                return;
            }

            if ($photoId) {
                \App\Models\ImagePlatformUpload::record($image->id, \App\Models\ImagePlatformUpload::PLATFORM_YELP_PORTFOLIO, [
                    'remote_id' => $photoId,
                ]);

                Log::channel('yelp')->info('Yelp: project image synced', [
                    'image_id' => $image->id,
                    'force_refresh' => $this->forceRefresh,
                    'photo_id' => $photoId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('yelp')->error('Yelp: unexpected job failure', [
                'image_id' => $this->imageId,
                'force_refresh' => $this->forceRefresh,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
