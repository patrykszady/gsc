<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SsSystems\Platform\Seo\Bing\BingSync;
use SsSystems\Platform\Seo\Bing\BingSyncSkipped;
use SsSystems\Platform\Seo\Bing\BingWebmasterClient;
use SsSystems\Platform\Seo\Bing\BingWriter;

/**
 * Sync Bing Webmaster Tools query stats and daily totals — the kit's
 * BingSync over this site's client and writer (0.8.0, 2026-09-23; the
 * loop that used to be inline here is the kit's now, shared with
 * jpeterson-design). Output lines kept as they were, for whoever reads
 * storage/logs/seo-bing-sync.log.
 */
class SyncBingWebmaster extends Command
{
    protected $signature = 'seo:bing-sync {--dry-run}';

    protected $description = 'Sync Bing Webmaster Tools query stats (free, API-key auth)';

    public function handle(BingWebmasterClient $client, BingWriter $writer): int
    {
        try {
            $summary = BingSync::run($client, $writer, ['dry_run' => (bool) $this->option('dry-run')]);
        } catch (BingSyncSkipped) {
            $this->error('Bing not configured. Save an API key under SEO → Connect Services.');

            return self::FAILURE;
        }

        if ($summary['status'] === 'failed') {
            $this->error('Fetch failed. '.$summary['error']);

            return self::FAILURE;
        }

        $this->info("Fetched {$summary['fetched']} rows from Bing WMT");

        if ($summary['status'] === 'dry-run') {
            foreach ($summary['sample'] as $row) {
                $this->line(json_encode($row));
            }

            return self::SUCCESS;
        }

        $this->info('Upserted '.($summary['inserted'] + $summary['updated']).' rows.');
        if ($summary['daily_totals_error'] !== null) {
            $this->warn('Daily-totals fetch failed (GetRankAndTrafficStats). '.$summary['daily_totals_error']);
        } else {
            $this->info("Daily totals upserted: {$summary['daily_totals']} day(s).");
        }

        return self::SUCCESS;
    }
}
