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
            $this->error('Yelp session: NOT authenticated - manual login required');
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
