<?php

namespace App\Console\Commands;

use App\Services\YelpBusinessService;
use Illuminate\Console\Command;

/**
 * Keep the biz.yelp.com session alive from the server.
 *
 * This is the replacement for "leave Chrome open on your desktop with the
 * Session Bridge extension installed". It visits the dashboard headlessly on a
 * schedule — which is the activity that keeps the session from ageing out —
 * and writes whatever cookies Yelp rotated back into the jar the upload jobs
 * read. No human browser, no admin tab, no extension pairing.
 */
class YelpKeepSession extends Command
{
    protected $signature = 'yelp:keep-session';

    protected $description = 'Refresh the biz.yelp.com session and jar from the server (no browser/extension needed)';

    public function handle(YelpBusinessService $yelp): int
    {
        if (! $yelp->isConfigured()) {
            $this->warn('Yelp business automation is not configured — nothing to keep alive.');

            return self::SUCCESS;
        }

        $authed = $yelp->keepSessionAlive();

        if ($authed === null) {
            // DataDome blocked the probe. We learned nothing about the session,
            // and saying so beats reporting a failure the operator would chase.
            $this->warn('Indeterminate — DataDome blocked the probe. Session flag left unchanged.');

            return self::SUCCESS;
        }

        if ($authed) {
            $this->info('Session alive; cookie jar refreshed from the live browser session.');

            return self::SUCCESS;
        }

        // checkSession() already called markSessionDead(), which dispatches the
        // unattended re-login when it can and emails the operator when it
        // cannot — so there is nothing to escalate here.
        $this->warn('Session is NOT authenticated. Recovery (auto re-login or operator email) has been triggered.');

        return self::SUCCESS;
    }
}
