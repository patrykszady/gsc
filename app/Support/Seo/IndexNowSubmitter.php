<?php

namespace App\Support\Seo;

use Illuminate\Support\Facades\Log;
use SsSystems\Platform\Seo\IndexNow\Contracts\IndexNowKey;
use SsSystems\Platform\Seo\IndexNow\IndexNowClient;

/**
 * This site's ad hoc single/batch IndexNow pings — observers
 * (TestimonialObserver, AreaServedObserver, ProjectObserver,
 * ProjectImageObserver, via the queued App\Jobs\SubmitUrlsToIndexNow),
 * App\Jobs\AnnounceBlogPostJob, App\Services\Seo\Appliers\CreatePageApplier,
 * the autopilot's ReindexApplier (via App\Console\Commands\
 * SeoReindexProblemPages), SeoGscCanonicalConflicts --warm,
 * SubmitDead404sToIndexNow and the manual indexnow:submit command all call
 * through here with the exact isEnabled()/submit()/submitBatch() surface
 * they always called SsSystems\Platform\Indexing\IndexNowService (the kit's
 * now-retired helper) through — only the implementation underneath moved
 * (kit 0.19.4) to IndexNowClient + IndexNowSiteKey, the same client
 * seo:indexnow's scheduled sweep uses.
 *
 * Carries no ledger bookkeeping of its own: IndexNowLedger's "what have we
 * already told Bing about" belongs to seo:indexnow's own hourly tick. A ping
 * from here is the same fire-and-forget, every-time submission the old
 * helper made — never throws (IndexNowClient::submit() already catches a
 * network failure into its own `failures` list), so a call site's existing
 * try/catch around it is a belt-and-braces, not a requirement.
 */
final class IndexNowSubmitter
{
    public function __construct(
        private readonly IndexNowClient $client,
        private readonly IndexNowKey $key,
    ) {}

    public function isEnabled(): bool
    {
        return filled($this->key->key());
    }

    public function submit(string $url): bool
    {
        return $this->submitBatch([$url]);
    }

    /**
     * @param  array<int, string>  $urls
     */
    public function submitBatch(array $urls): bool
    {
        $urls = array_values(array_filter(array_map('trim', $urls), static fn (string $url): bool => $url !== ''));
        $key = $this->key->key();

        if ($urls === [] || $key === null) {
            return false;
        }

        $host = (string) parse_url($this->key->keyLocation(), PHP_URL_HOST);
        $result = $this->client->submit($host, $urls, $key, $this->key->keyLocation());

        if ($result['failures'] !== []) {
            Log::channel('indexnow')->warning('IndexNow: submission had failures', [
                'urls' => $urls,
                'failures' => $result['failures'],
            ]);

            return false;
        }

        return $result['accepted'] > 0;
    }
}
