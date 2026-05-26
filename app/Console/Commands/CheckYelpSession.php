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
            Log::warning('Yelp daily session check: NOT authenticated');
            $this->error('Yelp session: NOT authenticated - manual login required');
            return self::FAILURE;
        }

        Log::warning('Yelp daily session check: indeterminate (script error / timeout)');
        $this->warn('Yelp session: could not determine');
        return self::FAILURE;
    }
}
