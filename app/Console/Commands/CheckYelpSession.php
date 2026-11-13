<?php

namespace App\Console\Commands;

use App\Services\YelpBusinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckYelpSession extends Command
{
    protected $signature = 'yelp:check-session';

    protected $description = 'Run a real headless check that the Yelp biz session is still authenticated and refresh the cached status.';

    public function handle(YelpBusinessService $service): int
    {
        if (! $service->isConfigured()) {
            $this->warn('Yelp business uploader is not configured; skipping session check.');
            return self::SUCCESS;
        }

        $authed = $service->checkSession();
        if ($authed === true) {
            $this->info('Yelp session: authenticated');
            return self::SUCCESS;
        }
        if ($authed === false) {
            Log::channel('yelp')->warning('Yelp daily session check: NOT authenticated');

            // checkSession() has already marked the session dead, which queues
            // the automatic sign-in when one can run. Say which it is — this
            // used to print "manual login required" either way. Still a
            // failure: leads and photo uploads are stopped until it is back.
            $paused = $service->autoLoginPaused();
            $this->error('Yelp session: NOT authenticated — '.match (true) {
                $paused !== null => "automatic sign-in is paused ({$paused['code']}); sign in from Admin → Platforms (Verify Login)",
                $service->canAutoLogin() && $service->autoLoginBudgetSpent() => 'automatic sign-in has used its '.YelpBusinessService::AUTO_LOGIN_DAILY_LIMIT.' attempts for the day; sign in from Admin → Platforms (Verify Login)',
                $service->canAutoLogin() => 'automatic sign-in has been queued (result in storage/logs/yelp-*.log)',
                default => 'automatic sign-in needs a captcha key and a proxy; sign in from Admin → Platforms (Verify Login)',
            });

            return self::FAILURE;
        }

        // Indeterminate = the check itself errored or timed out (e.g. Chromium
        // launch race, locked profile, network blip). Do NOT propagate as a
        // scheduler failure — that floods laravel.log with stack traces for
        // transient issues that aren't actionable. The warning still lands in
        // yelp.log so admins can spot patterns of repeated indeterminate runs.
        Log::channel('yelp')->warning('Yelp daily session check: indeterminate (script error / timeout)');
        $this->warn('Yelp session: could not determine (transient — not a hard failure)');
        return self::SUCCESS;
    }
}
