<?php

namespace App\Support\Seo;

use App\Models\BingCrawlStat;
use App\Models\BingUrlInfo;
use SsSystems\Platform\Seo\Bing\BingCoverageSync;

/**
 * This site's half of the kit's SsSystems\Platform\Seo\Bing\Contracts\
 * BingCoverageWriter — where BingCoverageSync::run()'s rows land, next to
 * the existing BingWriter. Both models carry BelongsToSite, so a row is
 * the current tenant's without anything said here.
 */
final class BingCoverageWriter implements \SsSystems\Platform\Seo\Bing\Contracts\BingCoverageWriter
{
    /**
     * whereDate(), never updateOrCreate(['date' => $date, …]) — same reason
     * as BingWriter::upsertDailyTotal(): a string match against a `date`
     * column only ever works by accident of how the DB driver truncates it.
     */
    public function upsertCrawlStat(array $row): void
    {
        $siteUrl = mb_substr((string) $row['site_url'], 0, 191);
        $attributes = $row;
        $attributes['site_url'] = $siteUrl;

        $existing = BingCrawlStat::whereDate('date', $row['date'])->where('site_url', $siteUrl)->first();

        if ($existing) {
            $existing->update($attributes);
        } else {
            BingCrawlStat::create($attributes);
        }
    }

    /**
     * updateOrCreate() only ever fills the keys $row actually carries, so a
     * row's other columns keep their stored value — exactly the "merge
     * only the keys given" the kit's interface asks for.
     */
    public function upsertUrlInfo(array $row): void
    {
        $urlHash = (string) $row['url_hash'];
        unset($row['url_hash']);

        if (isset($row['url'])) {
            $row['url'] = mb_substr((string) $row['url'], 0, 500);
        }

        BingUrlInfo::updateOrCreate(['url_hash' => $urlHash], $row);
    }

    public function urlsToInspect(array $sitemapUrls, int $limit): array
    {
        if ($sitemapUrls === [] || $limit <= 0) {
            return [];
        }

        $hashToUrl = [];
        foreach ($sitemapUrls as $url) {
            $hashToUrl[BingCoverageSync::hash($url)] = $url;
        }

        $inspectedAt = BingUrlInfo::query()
            ->whereIn('url_hash', array_keys($hashToUrl))
            ->pluck('inspected_at', 'url_hash');

        $never = [];
        $asked = [];

        foreach ($hashToUrl as $hash => $url) {
            $at = $inspectedAt->get($hash);

            if ($at === null) {
                $never[] = $url;
            } else {
                $asked[$url] = $at;
            }
        }

        // Never-asked first, then the longest ago.
        asort($asked);

        return array_slice(array_merge($never, array_keys($asked)), 0, $limit);
    }

    public function forgetUrlsNotIn(array $sitemapUrls): int
    {
        $hashes = array_map(static fn (string $url) => BingCoverageSync::hash($url), $sitemapUrls);

        return BingUrlInfo::query()
            ->where('in_sitemap', true)
            ->whereNotIn('url_hash', $hashes)
            ->update(['in_sitemap' => false]);
    }
}
