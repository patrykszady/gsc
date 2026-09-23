<?php

namespace App\Support\Seo;

use App\Models\BingDailyTotal;
use App\Models\BingTrafficStat;

/**
 * This site's half of the kit's SsSystems\Platform\Seo\Bing\BingWriter —
 * where BingSync::run()'s rows land. Both models carry BelongsToSite, so a
 * row is the current tenant's without anything said here. Same shape as
 * SearchConsoleWriter, for the same reasons.
 */
final class BingWriter implements \SsSystems\Platform\Seo\Bing\BingWriter
{
    public function upsertQueryStat(array $row): string
    {
        $stat = BingTrafficStat::updateOrCreate(['dim_hash' => $row['dim_hash']], $row);

        return $stat->wasRecentlyCreated ? 'inserted' : 'updated';
    }

    /**
     * whereDate(), never updateOrCreate(['date' => $date, …]) — the lookup
     * the command used to do, which only ever matched because MySQL's DATE
     * column truncates what Eloquent's `date` cast writes. See
     * SearchConsoleWriter::upsertDailyTotal() for the whole story.
     */
    public function upsertDailyTotal(string $date, string $siteUrl, array $totals): void
    {
        $siteUrl = mb_substr($siteUrl, 0, 191);
        $row = BingDailyTotal::whereDate('date', $date)->where('site_url', $siteUrl)->first();

        if ($row) {
            $row->update($totals);
        } else {
            BingDailyTotal::create($totals + ['date' => $date, 'site_url' => $siteUrl]);
        }
    }
}
