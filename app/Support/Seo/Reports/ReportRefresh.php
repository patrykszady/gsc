<?php

namespace App\Support\Seo\Reports;

use App\Http\Controllers\Api\Admin\V1\SeoReportController;
use App\Models\Site;
use App\Support\SeoStorage;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Reports\Console\ReportRun;

/**
 * Refreshes the report library in one pass: every available report (or only
 * the ones that need it), one after another, in the background. The Full
 * Report Library read "needs a refresh" most of the time (2026-09-29): a
 * report counts as fresh for 24 hours, and the per-report Run button is cut
 * off by production's 30-second PHP-FPM limit. Ported from hive2025's class
 * of the same name (see its docblock) — the shape and the two safeguards
 * (the lock, the 6-hour automatic retry backoff) are unchanged.
 *
 * gsc is multi-tenant, unlike hive2025. Every cache key below is namespaced
 * through Tenancy::cacheKey() (Site::current()-aware, same convention this
 * controller's own health/search-snapshot caches already use), and
 * ReportRun::run() resolves the tenant-scoped report path automatically
 * through the TenantScopedReportStorage this app binds to the kit's
 * ReportStorage contract — see that class's docblock. None of that works
 * unless Site::current() is ALREADY the right tenant when these methods run:
 * the admin API pins it via PinAdminApiTenant middleware; the console command
 * (App\Console\Commands\SeoReportsRefresh) binds it explicitly via
 * Tenancy::for(), since a scheduled or detached run has no request to pin it.
 *
 * Progress lives in the cache under PROGRESS_KEY so the admin can show
 * "3 of 7" while it runs. A report that runs cleanly but has nothing to say
 * (nothing ranking in 8-20 for content-gap, say) writes a dated note, so it
 * stops reading stale forever.
 */
class ReportRefresh
{
    public const PROGRESS_KEY = 'seo-reports:refresh';

    /** When each report was last attempted by the automatic pass. */
    public const ATTEMPTS_KEY = 'seo-reports:refresh-attempts';

    public const LOCK = 'seo-reports:refresh-lock';

    /** A report the hourly pass could not refresh waits this long before it is tried again. */
    public const RETRY_AFTER_HOURS = 6;

    /** @return array<string, mixed>|null */
    public static function progress(): ?array
    {
        $progress = Cache::get(Tenancy::cacheKey(self::PROGRESS_KEY));

        return is_array($progress) ? $progress : null;
    }

    public static function running(): bool
    {
        return (bool) (self::progress()['running'] ?? false);
    }

    /**
     * The reports a refresh would run: available ones, and with $onlyStale
     * only those not up to date.
     *
     * @param  list<string>  $keys  limit to these reports; empty = all
     * @return list<string>
     */
    public static function keysToRun(bool $onlyStale, array $keys = []): array
    {
        return collect(app(SeoReportController::class)->files())
            ->filter(fn (array $file) => $file['available'])
            ->filter(fn (array $file) => ! $onlyStale || $file['status'] !== 'fresh')
            ->filter(fn (array $file) => $keys === [] || in_array($file['key'], $keys, true))
            ->pluck('key')
            ->values()
            ->all();
    }

    /** Mark a batch as started before its background process picks it up. */
    public static function markQueued(array $keys): array
    {
        $progress = [
            'running' => true,
            'keys' => array_values($keys),
            'done' => [],
            'current' => null,
            'results' => [],
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ];

        Cache::put(Tenancy::cacheKey(self::PROGRESS_KEY), $progress, now()->addDay());

        return $progress;
    }

    /**
     * Run the reports one after another. Returns the final progress, or null
     * when another refresh already holds the lock.
     *
     * @param  list<string>  $keys  explicit reports; empty = every available one
     * @param  bool  $automatic  the hourly pass: skip reports it tried in the last RETRY_AFTER_HOURS
     * @param  (Closure(string, array, Request, int): array)|null  $runner  ReportRun::run unless a test swaps it
     * @return array<string, mixed>|null
     */
    public static function run(bool $onlyStale, array $keys = [], bool $automatic = false, ?Closure $runner = null): ?array
    {
        $lock = Cache::lock(Tenancy::cacheKey(self::LOCK), 3600);

        if (! $lock->get()) {
            return null;
        }

        try {
            $reports = (array) config('seo-reports.reports', []);
            $attemptsKey = Tenancy::cacheKey(self::ATTEMPTS_KEY);
            $attempts = (array) Cache::get($attemptsKey, []);
            $site = Site::current()?->slug;
            $runner ??= fn (string $key, array $meta, Request $request, int $trendDays) => ReportRun::run($key, $meta, $request, $trendDays, ['site' => $site]);

            $toRun = collect(self::keysToRun($onlyStale, $keys))
                ->reject(fn (string $key) => $automatic
                    && isset($attempts[$key])
                    && now()->subHours(self::RETRY_AFTER_HOURS)->lt($attempts[$key]))
                ->values()
                ->all();

            $progress = self::markQueued($toRun);
            $progressKey = Tenancy::cacheKey(self::PROGRESS_KEY);

            foreach ($toRun as $key) {
                $progress['current'] = $key;
                Cache::put($progressKey, $progress, now()->addDay());

                $before = self::modifiedAt($key);
                $run = $runner($key, $reports[$key], request(), 14);

                if (($run['ok'] ?? false) && self::modifiedAt($key) === $before) {
                    self::writeNothingToReport($key, $reports[$key], self::lastLine((string) ($run['output_tail'] ?? '')));
                }

                $attempts[$key] = now()->toIso8601String();
                $progress['done'][] = $key;
                $progress['results'][$key] = $run['status'] ?? (($run['ok'] ?? false) ? 'ok' : 'failed');
            }

            $progress['current'] = null;
            $progress['running'] = false;
            $progress['finished_at'] = now()->toIso8601String();
            Cache::put($progressKey, $progress, now()->addDay());
            Cache::put($attemptsKey, $attempts, now()->addWeek());

            // Same two caches regenerate() busts, so a "Refresh all" pass
            // leaves the admin's health score and search snapshot no
            // staler than a single manual Run would have.
            Cache::forget(Tenancy::cacheKey('admin.seo-reports.health-snapshot'));
            Cache::forget(app(SeoReportController::class)->searchSnapshotCacheKey(14));

            Log::channel('seo-reports')->info('report library refreshed', [
                'reports' => $progress['done'],
                'results' => $progress['results'],
                'automatic' => $automatic,
                'site' => $site,
            ]);

            return $progress;
        } finally {
            $lock->release();
        }
    }

    protected static function modifiedAt(string $key): ?int
    {
        $path = SeoStorage::path("reports/{$key}.md");
        $disk = Storage::disk('local');

        clearstatcache();

        return $disk->exists($path) ? $disk->lastModified($path) : null;
    }

    protected static function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output))));

        return $lines === [] ? '' : (string) end($lines);
    }

    /** A clean run with nothing to write still counts as checked today. */
    protected static function writeNothingToReport(string $key, array $meta, string $message): void
    {
        $note = trim($message) !== '' ? trim($message) : 'Nothing to report this time.';

        Storage::disk('local')->put(
            SeoStorage::path("reports/{$key}.md"),
            "# {$meta['label']}\n\n_Checked ".now()->format('M j, Y g:i A').": {$note}_\n",
        );
    }
}
