<?php

namespace App\Console\Commands;

use App\Services\InstagramRemoteLoginService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Daily authenticated-session check for the Instagram puppeteer profile —
 * the same treatment the Yelp session gets (yelp:check-session).
 *
 * Doubles as the keepalive: the check drives a real headless visit to
 * instagram.com with the persisted profile, which is exactly the activity
 * that stops an idle session from expiring. Before this, the profile only
 * saw traffic on the twice-a-week posting schedule and nothing watched
 * whether it was still logged in between posts — the session could die on
 * Monday and the first symptom was Thursday's post silently failing.
 */
class CheckInstagramSession extends Command
{
    protected $signature = 'instagram:check-session';

    protected $description = 'Headless check that the Instagram session is still authenticated; refreshes the cached status and keeps the session warm.';

    public function handle(InstagramRemoteLoginService $service): int
    {
        if (! $service->isEnabled()) {
            $this->warn('Instagram puppeteer login is not enabled; skipping session check.');

            return self::SUCCESS;
        }

        $authed = $service->checkSession();

        Cache::put('instagram.session.last_check', [
            'at' => now()->toIso8601String(),
            'authenticated' => $authed,
        ], now()->addDays(3));

        if ($authed === true) {
            $this->info('Instagram session: authenticated');

            return self::SUCCESS;
        }

        if ($authed === false) {
            Log::warning('Instagram daily session check: NOT authenticated — re-login needed via the admin Platforms panel');
            $this->error('Instagram session: NOT authenticated - manual login required');

            return self::FAILURE;
        }

        // null = the check itself could not run (script/chrome failure).
        Log::warning('Instagram daily session check could not run (script error)');
        $this->error('Instagram session check could not run');

        return self::FAILURE;
    }
}
