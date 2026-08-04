<?php

namespace App\Services\Seo\Appliers;

use App\Models\SeoAction;
use App\Services\Seo\ActionApplier;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Warms a coverage-problem URL so the next crawl sees a fast 200, and pings
 * IndexNow (seo:reindex-problem-pages --urls=…).
 *
 * READ THIS BEFORE TRUSTING THE LEDGER. IndexNow reaches Bing, Yandex and
 * Seznam. **Google has never supported it.** This category was 110 of the
 * first 159 autopilot actions — 69% of everything the loop did — and 24 of
 * the URLs it "reindexed" were still reported by Search Console as literally
 * `URL is unknown to Google`. The cache warm is real and useful; the Google
 * reindex the name implies is not something this action can deliver.
 *
 * A URL Google has never crawled is almost always ORPHANED rather than
 * unnoticed: 140 of the 207 areas-served URLs in the sitemap have zero
 * internal links from anywhere on the site. Pinging an orphan cannot fix it —
 * only a crawl path can. apply() therefore records whether the target is
 * reachable by link, so the ledger stops reporting success on URLs that
 * cannot benefit.
 *
 * Inherently idempotent and non-destructive — a re-crawl ping cannot be "undone"
 * and re-pinging is harmless — so revert() is a documented no-op.
 */
class ReindexApplier implements ActionApplier
{
    public function category(): string
    {
        return 'reindex';
    }

    public function apply(SeoAction $action): void
    {
        $url = $action->target_url ?: ($action->payload['url'] ?? null);
        if (! $url) {
            throw new RuntimeException("reindex action #{$action->id} has no target_url.");
        }

        Artisan::call('seo:reindex-problem-pages', ['--urls' => [$url]]);

        // Truthful outcome, so a run of "applied" rows cannot be mistaken for
        // a run of indexed pages.
        $payload = $action->payload ?? [];
        $payload['reaches_google'] = false;
        $payload['mechanism'] = 'cache-warm + IndexNow (Bing/Yandex/Seznam only)';
        $payload['orphaned'] = $this->isOrphaned($url);
        $action->payload = $payload;
    }

    /**
     * Is this URL reachable by a link, or only from the sitemap?
     *
     * Sitemap presence is not a crawl path. A URL reachable only from the
     * sitemap is exactly what lands in "Discovered - currently not indexed",
     * and no amount of pinging changes that.
     *
     * Measured by fetching the URL's most likely parent and looking for the
     * link, rather than a link index — there is no crawl table in this app,
     * and inventing one here would be a much larger change than this applier
     * warrants. Returns null when it cannot tell, which is recorded as-is:
     * an honest unknown beats a confident guess in a ledger used to decide
     * what the loop does next.
     */
    protected function isOrphaned(string $url): ?bool
    {
        $path = '/' . ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $parent = preg_replace('#/[^/]+$#', '', $path) ?: '/';

        try {
            $html = (string) Http::timeout(10)->get(url($parent))->body();
            if ($html === '') {
                return null;
            }

            return ! str_contains($html, 'href="' . $path . '"')
                && ! str_contains($html, 'href="' . url($path) . '"');
        } catch (\Throwable) {
            return null;
        }
    }

    public function revert(SeoAction $action): void
    {
        // No-op: a crawl-request ping is not reversible and is harmless to leave.
    }
}
