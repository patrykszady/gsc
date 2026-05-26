<?php

namespace App\Jobs;

use App\Models\ProjectImage;
use App\Services\YelpBusinessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UploadProjectImageToYelp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(
        public int $imageId,
        public bool $forceRefresh = false,
    ) {}

    // Sequencing is enforced by the media-sync Horizon supervisor running with
    // --max-processes=1 --balance=simple. We intentionally do NOT use
    // WithoutOverlapping here — releasing-with-delay would push jobs into the
    // delayed ZSET and make pending uploads hard to wipe in an emergency.
    // With one worker, pending jobs sit in the plain queues:media-sync LIST
    // and `redis-cli DEL queues:media-sync` clears them instantly.

    public function handle(YelpBusinessService $service): void
    {
        $image = ProjectImage::with('project')->find($this->imageId);

        if (! $image) {
            Log::warning('Yelp: image not found', ['image_id' => $this->imageId]);
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
            Log::info('Yelp: service not configured, skipping');
            return;
        }

        if (empty($project->yelp_portfolio_url)) {
            Log::info('Yelp: project has no yelp_portfolio_url, skipping', [
                'project_id' => $project->id,
            ]);
            return;
        }

        $photoId = $service->uploadProjectImage($image);

        if ($photoId) {
            $image->update([
                'yelp_photo_id' => $photoId,
                'yelp_uploaded_at' => now(),
            ]);

            Log::info('Yelp: project image synced', [
                'image_id' => $image->id,
                'force_refresh' => $this->forceRefresh,
                'photo_id' => $photoId,
            ]);
        }
    }
}
