<?php

namespace App\Jobs;

use App\Models\ProjectImage;
use App\Services\YelpBusinessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Upload a ProjectImage to the account-wide Yelp Business Photos gallery.
 *
 * Companion to UploadProjectImageToYelp (which targets per-project portfolios).
 * Fires for any published project image once Yelp is configured, no
 * yelp_portfolio_url required.
 */
class UploadProjectImageToYelpBusinessPhotos implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;
    public int $backoff = 60;

    public function __construct(
        public int $imageId,
        public bool $forceRefresh = false,
    ) {}

    /**
     * Serialize uploads so Puppeteer's Chromium userDataDir is not
     * accessed concurrently (would corrupt the session lock). Shares the
     * same lock as UploadProjectImageToYelp on purpose.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('yelp-portfolio-upload'))->expireAfter(600)];
    }

    public function handle(YelpBusinessService $service): void
    {
        // Always clear the "queued" marker once the job actually runs so the
        // next sync command can re-queue this image if it ends up not being
        // uploaded (e.g. unpublished, missing config, upload failure).
        Cache::forget('yelp_biz_upload_queued:' . $this->imageId);

        $image = ProjectImage::with('project')->find($this->imageId);
        if (! $image) {
            Log::warning('Yelp biz: image not found', ['image_id' => $this->imageId]);
            return;
        }

        if (! $image->project || ! $image->project->is_published) {
            return;
        }

        if ($image->yelp_biz_uploaded_at && ! $this->forceRefresh) {
            return;
        }

        if (! $service->isConfigured()) {
            Log::info('Yelp biz: service not configured, skipping');
            return;
        }

        $result = $service->uploadProjectImageToBusinessPhotos($image);

        if ($result) {
            $image->update([
                'yelp_biz_photo_id' => $result['photo_id'],
                'yelp_biz_uploaded_at' => now(),
                'yelp_biz_photos_url' => $result['photos_url'] ?? $image->yelp_biz_photos_url,
            ]);

            Log::info('Yelp biz: project image synced to business gallery', [
                'image_id' => $image->id,
                'force_refresh' => $this->forceRefresh,
                'photo_id' => $result['photo_id'],
            ]);
        }
    }

    public function failed(?\Throwable $e = null): void
    {
        Cache::forget('yelp_biz_upload_queued:' . $this->imageId);
    }
}
