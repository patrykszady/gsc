<?php

namespace App\Console\Commands;

use App\Services\GoogleSearchConsoleService;
use App\Support\Seo\SearchConsoleWriter;
use Illuminate\Console\Command;
use SsSystems\Platform\Seo\SearchConsoleSync;
use SsSystems\Platform\Seo\SearchConsoleSyncSkipped;

/**
 * Sync Google Search Console search-analytics data.
 *
 * This is now a thin wrapper: every real step (the window math, the
 * paginated query/page/country/device pull, the true daily totals, the
 * per-day search-appearance breakdown, dim_hash, all of it) lives once in
 * the shared kit's SsSystems\Platform\Seo\SearchConsoleSync::run(), so this
 * site and jpeterson-design stop maintaining two copies of the same
 * algorithm. This command only reads the CLI options, hands them to the
 * kit, and prints what came back — this site's own
 * GoogleSearchConsoleService (bound to SearchConsoleSyncClient in
 * AppServiceProvider) supplies the Google calls, and
 * App\Support\Seo\SearchConsoleWriter (bound to SearchConsoleWriter) is
 * where the results land.
 */
class SyncGoogleSearchConsole extends Command
{
    protected $signature = 'seo:gsc-sync
        {--days= : Number of days back to sync (defaults to the kit'."'".'s SearchConsoleSyncRule::DEFAULT_DAYS)}
        {--lag-days= : Skip the most recent N days, GSC data lags (defaults to the kit'."'".'s SearchConsoleSyncRule::DEFAULT_LAG_DAYS)}
        {--site= : Override site URL (default from config)}
        {--limit=25000 : Max rows per page}
        {--queue : Deprecated no-op alias for compatibility}
        {--dry-run}';

    protected $description = 'Sync Google Search Console query/page/country/device metrics';

    public function handle(GoogleSearchConsoleService $client, SearchConsoleWriter $writer): int
    {
        if ((bool) $this->option('queue')) {
            $cmdLine = implode(' ', $_SERVER['argv'] ?? []);
            logger('seo-sync')->warning('Deprecated --queue option used for seo:gsc-sync', [
                'command_line' => $cmdLine,
                'calling_user' => get_current_user(),
                'pid' => getmypid(),
                'timestamp' => now()->toIso8601String(),
            ]);
            $this->warn('Option --queue is deprecated and ignored for seo:gsc-sync.');
            $this->line("Command line: {$cmdLine}");
        }

        // No numeric defaults on --days/--lag-days here: an unset option
        // stays out of $options entirely, so SearchConsoleSync::run() falls
        // through to the kit's own SearchConsoleSyncRule constants rather
        // than this command silently re-deciding the window on its own.
        $options = array_filter([
            'days' => $this->option('days') !== null ? max(1, (int) $this->option('days')) : null,
            'lag_days' => $this->option('lag-days') !== null ? max(0, (int) $this->option('lag-days')) : null,
            'site_url' => $this->option('site') ?: null,
            'limit' => (int) $this->option('limit'),
            'dry_run' => (bool) $this->option('dry-run'),
        ], fn ($value) => $value !== null);

        try {
            $summary = SearchConsoleSync::run($client, $writer, $options);
        } catch (SearchConsoleSyncSkipped $e) {
            // A skipped run — no Search Console grant on file — is not a
            // failure: the owner simply hasn't connected it yet, or
            // disconnected it. Returning FAILURE here used to make the
            // three-hourly schedule log an error forever for every
            // unconnected tenant. SUCCESS + an info line is a deliberate
            // change from that: it matches jpeterson-design's port and is
            // what the kit's own docblock expects every site to do.
            $this->info($e->getMessage());

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Search Console sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $dryNote = $summary['status'] === 'dry-run' ? ' (dry-run)' : '';

        $this->info("Site: {$summary['site_url']}");
        $this->info("Range: {$summary['window_start']} → {$summary['window_end']}");
        $this->info("Done. Inserted={$summary['inserted']} Updated={$summary['updated']}{$dryNote}");
        $this->info("Daily totals upserted: {$summary['daily_totals']} day(s){$dryNote}");
        $this->info("Search-appearance rows: {$summary['appearance_rows']}{$dryNote}");

        return self::SUCCESS;
    }
}
