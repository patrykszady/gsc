<?php

namespace App\Jobs;

use App\Models\ProjectImage;
use App\Services\YelpBusinessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    /**
     * Serialize uploads so Puppeteer's Chromium userDataDir is not
     * accessed concurrently (would corrupt the session lock).
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('yelp-portfolio-upload'))->expireAfter(600)];
    }

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
