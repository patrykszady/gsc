<?php

namespace App\Jobs;

use App\Exceptions\YelpSessionExpiredException;
use App\Exceptions\YelpUploadThrottledException;
use App\Models\ProjectImage;
use App\Services\YelpBusinessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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
class UploadProjectImageToYelpBusinessPhotos implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // tries is high because we lean on `release()` to back off when the
    // hard min-interval throttle says "not yet". `retryUntil()` is the
    // real bound \u2014 12h of attempts then give up.
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
        // Always clear the "queued" marker once the job actually runs so the
        // next sync command can re-queue this image if it ends up not being
        // uploaded (e.g. unpublished, missing config, upload failure).
        try {
            $image = ProjectImage::with('project')->find($this->imageId);
            if (! $image) {
                Cache::forget('yelp_biz_upload_queued:' . $this->imageId);
                Log::channel('yelp')->warning('Yelp biz: image not found', ['image_id' => $this->imageId]);
                return;
            }

            if (! $image->project || ! $image->project->is_published) {
                Cache::forget('yelp_biz_upload_queued:' . $this->imageId);
                Log::channel('yelp')->info('Yelp biz: skipping unpublished project', [
                    'image_id' => $this->imageId,
                    'project_id' => $image->project_id,
                    'project_published' => $image->project?->is_published ?? false,
                ]);
                return;
            }

            if ($image->yelp_biz_uploaded_at && ! $this->forceRefresh) {
                Cache::forget('yelp_biz_upload_queued:' . $this->imageId);
                Log::channel('yelp')->info('Yelp biz: already uploaded, skipping', [
                    'image_id' => $this->imageId,
                    'yelp_biz_photo_id' => $image->yelp_biz_photo_id,
                    'uploaded_at' => $image->yelp_biz_uploaded_at?->toIso8601String(),
                ]);
                return;
            }

            if (! $service->isConfigured()) {
                Cache::forget('yelp_biz_upload_queued:' . $this->imageId);
                Log::channel('yelp')->info('Yelp biz: service not configured, skipping');
                return;
            }

            try {
                $result = $service->uploadProjectImageToBusinessPhotos($image);
            } catch (YelpUploadThrottledException $e) {
                // Another upload is in flight or the min-interval has not
                // elapsed. Release this job back to the queue so the worker
                // picks it up after the throttle window. Keep the "queued"
                // marker so duplicate dispatches still no-op.
                //
                // Debug-level: this is the expected hot-path while a queue
                // of pending jobs waits for the active upload to finish.
                // INFO would flood the log with N lines per upload cycle.
                Log::channel('yelp')->debug('Yelp biz: throttled, releasing job', [
                    'image_id' => $this->imageId,
                    'retry_after_seconds' => $e->retryAfterSeconds,
                ]);
                $this->release($e->retryAfterSeconds);
                return;
            } catch (YelpSessionExpiredException $e) {
                // Persistent Chromium profile is no longer logged in. Driving
                // /login from this unattended job triggers DataDome and burns
                // 2captcha credit, so we fail the job immediately. Admin must
                // re-login interactively via /admin/platforms (Verify Login).
                Cache::forget('yelp_biz_upload_queued:' . $this->imageId);
                Log::channel('yelp')->warning('Yelp biz: session expired, failing job - admin must re-login via /admin/platforms', [
                    'image_id' => $this->imageId,
                    'error' => $e->getMessage(),
                ]);
                $this->fail($e);
                return;
            }

            Cache::forget('yelp_biz_upload_queued:' . $this->imageId);

            if ($result) {
                Log::channel('yelp')->info('Yelp biz: project image synced to business gallery', [
                    'image_id' => $image->id,
                    'force_refresh' => $this->forceRefresh,
                    'photo_id' => $result['photo_id'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            Cache::forget('yelp_biz_upload_queued:' . $this->imageId);
            Log::channel('yelp')->error('Yelp biz: unexpected job failure', [
                'image_id' => $this->imageId,
                'force_refresh' => $this->forceRefresh,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?\Throwable $e = null): void
    {
        Cache::forget('yelp_biz_upload_queued:' . $this->imageId);
    }
}
