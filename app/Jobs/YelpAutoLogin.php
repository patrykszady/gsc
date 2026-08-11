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
 * Log in to Yelp in the background using the stored email/password.
 *
 * This is what makes the whole thing "just provide login info": nothing else
 * is required of the operator. Dispatched when the session dies, from the
 * admin "Log in now" button, and on a schedule.
 *
 * Runs on `media-sync` for the same reasons VerifyYelpCookies does — the
 * `default` supervisor kills at 60s/128MB and would SIGKILL Chromium
 * mid-login, leaving a locked profile behind; media-sync allows 360s/512MB
 * and runs a single worker, which serialises this against the upload jobs
 * that share the profile directory.
 */
class YelpAutoLogin implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // One attempt. Retrying a rejected password or an unsolvable challenge
    // just burns captcha credit and another Chromium launch.
    public int $tries = 1;
    // Must exceed loginHeadless()'s worst case (script budget + captcha-solve
    // headroom + process grace) or the worker SIGKILLs Chromium mid-login.
    public int $timeout = 600;
    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return 'yelp-auto-login';
    }

    public function handle(YelpBusinessService $service): void
    {
        // Never spend captcha credit more often than this. Without a floor, a
        // wrong password plus a busy upload queue would relaunch Chromium on
        // every failed job for as long as the backlog lasts.
        if (Cache::has('yelp.auto_login_attempted')) {
            Log::channel('yelp')->debug('Yelp auto-login: skipped, attempted recently');
            return;
        }
        Cache::put('yelp.auto_login_attempted', true, now()->addMinutes(30));

        $result = $service->loginHeadless();

        Cache::put('yelp.auto_login_result', $result, now()->addDays(30));
        Cache::put('yelp.auto_login_at', now()->toIso8601String(), now()->addDays(30));

        if ($result['ok']) {
            Log::channel('yelp')->info('Yelp auto-login: success');
            return;
        }

        // A rejected password or a verification prompt cannot be fixed by
        // trying again — surface it so the admin UI can say what to do.
        Log::channel('yelp')->warning('Yelp auto-login: failed', $result);

        // ...and tell a human, for the codes only a human can clear. Until now
        // this job logged and stopped: markSessionDead() emails ONLY when it
        // decides not to auto-login, so once auto-login was viable the operator
        // heard nothing at all when it then failed. That is the same silent
        // hole that let a dead session sit from June 14 to July 14 while every
        // new project photo skipped Yelp.
        //
        // 'busy' and 'crashed' are transient — the next keepalive or upload
        // retries them — so they must not page anyone.
        if (in_array($result['code'] ?? '', ['needs_verification', 'bad_credentials'], true)) {
            $service->reportAutoLoginUnrecoverable(
                sprintf('Automatic re-login failed (%s). %s', $result['code'], $result['hint'] ?? '')
            );
        }
    }
}
