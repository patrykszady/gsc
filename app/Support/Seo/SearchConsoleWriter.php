<?php

namespace App\Support\Seo;

use App\Models\GscDailyTotal;
use App\Models\GscQueryMetric;
use App\Models\OAuthToken;
use App\Models\Site;
use App\Services\GoogleSearchConsoleService;
use App\Support\Tenancy;
use SsSystems\Platform\Seo\SearchConsoleWriter as SearchConsoleWriterContract;

/**
 * Where SsSystems\Platform\Seo\SearchConsoleSync::run() writes its results
 * for THIS site — bound to the kit's SearchConsoleWriter interface in
 * AppServiceProvider. Every write here is tenant-scoped: the query-metric
 * and daily-total models use BelongsToSite, and the raw search-appearance
 * table goes through Tenancy::table() rather than DB::table() directly (see
 * that method's own docblock for the incident — every raw DB::table() write
 * to a per-site table bypasses Eloquent's global scope and reads/writes
 * every tenant's rows at once — that taught this).
 */
final class SearchConsoleWriter implements SearchConsoleWriterContract
{
    public function upsertQueryMetric(array $row): string
    {
        $metric = GscQueryMetric::updateOrCreate(['dim_hash' => $row['dim_hash']], $row);

        return $metric->wasRecentlyCreated ? 'inserted' : 'updated';
    }

    public function upsertDailyTotal(string $date, string $siteUrl, array $totals): void
    {
        // Not updateOrCreate(['date' => $date, ...]): Eloquent's `date` cast
        // always writes the column as a full 'Y-m-d H:i:s' string, and only
        // MySQL's DATE type truncates that back on write — which is the
        // single reason an exact-string match ever finds the row again in
        // production. Anywhere without that truncation (sqlite, what this
        // suite runs on) every re-sync misses and falls through to an insert
        // that hits the (date, site_url) unique index. whereDate() normalizes
        // both sides through SQL's DATE(), so it works on both. Found by
        // jpeterson-design's port, which is tested on sqlite — see
        // GscDailyTotalLookupTest, which pins this half independently of the
        // sync itself.
        $siteKey = mb_substr($siteUrl, 0, 191);

        $row = GscDailyTotal::whereDate('date', $date)->where('site_url', $siteKey)->first();

        if ($row) {
            $row->update($totals);
        } else {
            GscDailyTotal::create($totals + ['date' => $date, 'site_url' => $siteKey]);
        }
    }

    public function upsertSearchAppearance(string $date, string $appearance, array $metrics): void
    {
        Tenancy::table('gsc_search_appearance_metrics')->updateOrInsert(
            ['site_id' => Site::current()?->id, 'date' => $date, 'appearance' => $appearance],
            $metrics + ['updated_at' => now(), 'created_at' => now()],
        );
    }

    public function recordSyncRun(array $summary): void
    {
        $token = OAuthToken::forProvider(GoogleSearchConsoleService::PROVIDER);

        // A skipped run with no grant on file has no token row to stamp the
        // summary onto — gscStatus() reads the resulting keys null-safely,
        // so an unconnected tenant just shows nothing rather than an error.
        if (! $token) {
            return;
        }

        $token->update(['metadata' => array_merge($token->metadata ?? [], ['sync' => $summary])]);
    }
}
