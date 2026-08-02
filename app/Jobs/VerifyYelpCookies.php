<?php

namespace App\Jobs;

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
 * Verify a freshly-ingested Yelp cookie jar, then resume uploads.
 *
 * Dispatched by YelpCookieIngestController after the browser extension hands
 * over a session. Launches a headless Chromium against the automation profile
 * with the new cookies injected; on success the pending photo backlog is
 * re-queued so the operator's only action was logging in to Yelp normally.
 *
 * MUST run on `media-sync`: checkSession() allows 90s of Chromium and peaks
 * around 800MB, while the `default` supervisor kills at 60s / 128MB — that
 * would SIGKILL Chromium mid-run and leave a locked user_data_dir behind.
 * media-sync also runs maxProcesses=1, which serialises this against the
 * upload jobs sharing the same profile directory.
 */
class VerifyYelpCookies implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // One attempt. A failed verification is not a transient error worth
    // retrying — either the cookies authenticate or the operator needs to log
    // in again, and a retry just burns another 90s Chromium launch.
    public int $tries = 1;
    public int $timeout = 180;
    public int $uniqueFor = 300;

    /** Rapid re-pushes from the extension's alarm collapse into one run. */
    public function uniqueId(): string
    {
        return 'yelp-verify-cookies';
    }

    public function handle(YelpBusinessService $service): void
    {
        if (! $service->isConfigured()) {
            Log::channel('yelp')->info('Yelp cookie verify: service not configured, skipping');
            return;
        }

        // Tri-state, and `false` is destructive: it marks the session dead,
        // which aborts every queued upload for 12h and emails the admin.
        // `null` means the probe could not tell (timeout, killed subprocess,
        // or DataDome eating the check) and must not be treated as failure.
        $authed = $service->checkSession();

        if ($authed === null) {
            Cache::put('yelp.cookies_verified', 'indeterminate', now()->addDays(60));
            Log::channel('yelp')->warning('Yelp cookie verify: indeterminate — leaving session state unchanged');
            return;
        }

        if ($authed === false) {
            Cache::put('yelp.cookies_verified', 'failed', now()->addDays(60));
            Log::channel('yelp')->warning('Yelp cookie verify: imported cookies did NOT authenticate');
            return;
        }

        Cache::put('yelp.cookies_verified', 'ok', now()->addDays(60));

        // Requeue explicitly rather than relying on checkSession()'s implicit
        // path: markSessionFresh() short-circuits to 0 when the dead flag was
        // already clear, which is the common case here (the extension may push
        // while the session is merely stale, not flagged dead). The call is
        // idempotent — the queued marker and in-flight guards make a
        // double-requeue a no-op.
        $requeued = $service->requeueMissingBusinessPhotos();

        Log::channel('yelp')->info('Yelp cookie verify: session authenticated from extension hand-off', [
            'requeued' => $requeued,
        ]);
    }

    public function failed(?\Throwable $e = null): void
    {
        Cache::put('yelp.cookies_verified', 'failed', now()->addDays(60));
        Log::channel('yelp')->error('Yelp cookie verify: job failed', [
            'error' => $e?->getMessage(),
        ]);
    }
}
