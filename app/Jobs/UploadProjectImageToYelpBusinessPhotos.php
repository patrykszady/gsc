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
        public ?bool $failOnOops = null,
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

            // Crash-safe retry guard: if a previous attempt set the in-flight
            // marker and never cleared it, the parent PHP process was killed
            // mid-upload (SIGKILL, OOM, deploy). The photo MAY already be on
            // Yelp — re-uploading would create a duplicate. Fail permanently
            // so an admin can verify on biz.yelp.com (caption ends with
            // `·#g{imageId}`) and decide whether to mark uploaded or clear
            // the marker for a retry.
            $inFlightKey = YelpBusinessService::inFlightCacheKey($this->imageId);
            if (Cache::has($inFlightKey)) {
                $marker = Cache::get($inFlightKey);
                Cache::forget('yelp_biz_upload_queued:' . $this->imageId);
                Log::channel('yelp')->warning('Yelp biz: in-flight marker present from prior killed attempt, refusing to re-upload (manual verification required)', [
                    'image_id' => $this->imageId,
                    'in_flight_marker' => is_string($marker) ? $marker : null,
                    'hint' => "Search biz.yelp.com photos for caption ending with '·#g{$this->imageId}'. If found, run: php artisan tinker -- \\App\\Models\\ImagePlatformUpload::record({$this->imageId}, 'yelp_biz', ['remote_id' => null, 'caption' => null]); \\Cache::forget('".$inFlightKey."');",
                ]);
                $this->fail(new \RuntimeException(
                    'Yelp biz: previous upload attempt was killed mid-flight; manual verification required to avoid duplicate'
                ));
                return;
            }

            try {
                $result = $service->uploadProjectImageToBusinessPhotos($image);
            } catch (YelpUploadThrottledException $e) {
                // When --fail-on-oops is set, treat photos_page_oops as a
                // hard failure for this image instead of releasing. Lets
                // operators burn through the queue without waiting on Yelp's
                // server-side ~10min throttle window after each success.
                if (($this->failOnOops ?? false) && $e->reason === 'photos_page_oops') {
                    Cache::forget('yelp_biz_upload_queued:' . $this->imageId);
                    Cache::forget(YelpBusinessService::inFlightCacheKey($this->imageId));
                    Log::channel('yelp')->warning('Yelp biz: photos_page_oops, failing job (fail-on-oops mode)', [
                        'image_id' => $this->imageId,
                    ]);
                    $this->fail($e);
                    return;
                }
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
                // Per-job jitter (0–30s) prevents N released jobs from all
                // waking at the same second when a host-wide cooldown
                // expires and stampeding the lock.
                $this->release($e->retryAfterSeconds + random_int(0, 30));
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
            // Re-throw so Laravel marks the job as failed and runs failed().
            // The signal-9 detection in failed() preserves the in-flight
            // marker so the next dispatch hits the duplicate-upload guard
            // instead of silently double-uploading to Yelp.
            throw $e;
        }
    }

    public function failed(?\Throwable $e = null): void
    {
        Cache::forget('yelp_biz_upload_queued:' . $this->imageId);

        // SIGKILL / SIGTERM path: Symfony Process throws with this message
        // when the subprocess is killed by a signal. The photo MAY have been
        // accepted by Yelp before the parent process died — leave the
        // in-flight marker in place so future retries refuse to re-upload
        // until an admin verifies.
        $msg = $e?->getMessage() ?? '';
        if (str_contains($msg, 'signal "9"') || str_contains($msg, 'signal "15"')) {
            Log::channel('yelp')->warning('Yelp biz: job killed by signal — upload may have committed on Yelp; manual verification required', [
                'image_id' => $this->imageId,
                'error' => $msg,
                'in_flight_key' => YelpBusinessService::inFlightCacheKey($this->imageId),
                'caption_marker' => '·#g' . $this->imageId,
            ]);
            return;
        }

        // Permanent verification-required failure (in-flight marker hit on
        // retry). Clear the marker only when the admin manually confirms.
        if (str_contains($msg, 'killed mid-flight')) {
            return;
        }

        // All other failure paths: clear the in-flight marker so a future
        // dispatch can proceed normally.
        Cache::forget(YelpBusinessService::inFlightCacheKey($this->imageId));
    }
}
