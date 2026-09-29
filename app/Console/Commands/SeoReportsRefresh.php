<?php

namespace App\Console\Commands;

use App\Support\Seo\Reports\ReportRefresh;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Refresh the SEO report library in one pass — see ReportRefresh. The admin's
 * "Refresh all" starts it in the background; the schedule runs it hourly with
 * --stale --automatic so each report is rewritten about once a day.
 *
 * gsc is multi-tenant: the admin API this feeds is pinned to the 'gsc' tenant
 * (App\Http\Middleware\PinAdminApiTenant), but this command itself has no
 * request to inherit a tenant from — a scheduled run and a detached run
 * dispatched by RunArtisanCommandDetached (a generic kit job that has never
 * heard of a "site") both start with Site::current() falling back to whatever
 * config('sites.default') says. That happens to be 'gsc' today, but nothing
 * enforces it stays that way, so --site makes the tenant explicit rather than
 * inherited: the admin passes its own pinned tenant on every dispatch, and the
 * schedule pins the same slug directly (see routes/console.php).
 */
class SeoReportsRefresh extends Command
{
    protected $signature = 'seo:reports-refresh
        {--stale : Only reports that are not up to date}
        {--automatic : The scheduled pass: skip a report it tried in the last few hours}
        {--keys= : Only these reports, comma-separated (one value, so the kit\'s detached runner can pass it)}
        {--site= : Tenant slug to run as; defaults to config(\'sites.default\')}';

    protected $description = 'Refresh every available SEO report, one after another';

    public function handle(): int
    {
        $slug = (string) ($this->option('site') ?: config('sites.default', 'gsc'));
        $site = Tenancy::find($slug);

        if (! $site) {
            $this->error("Unknown site \"{$slug}\".");

            return self::FAILURE;
        }

        $progress = Tenancy::for($site, fn () => ReportRefresh::run(
            onlyStale: (bool) $this->option('stale'),
            keys: array_values(array_filter(array_map('trim', explode(',', (string) $this->option('keys'))))),
            automatic: (bool) $this->option('automatic'),
        ));

        if ($progress === null) {
            $this->info('A refresh is already running.');

            return self::SUCCESS;
        }

        if ($progress['done'] === []) {
            $this->info('Every report is up to date.');

            return self::SUCCESS;
        }

        foreach ($progress['results'] as $key => $status) {
            $this->line("{$key}: {$status}");
        }

        return self::SUCCESS;
    }
}
