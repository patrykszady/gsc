<?php

namespace App\Support\Seo\Reports;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Reports\Contracts\PageFetcher;
use SsSystems\Platform\Reports\PageFetchResult;

/**
 * One GET request with a bounded timeout and an identifying User-Agent —
 * the kit's `page_fetcher` capability. Every self-crawl report (gbp-parity,
 * internal-link-suggest, schema-audit, health-check, area-pages-audit)
 * passes its OWN timeout/UA (see PageFetcher's docblock — they genuinely
 * differ and must not be standardised here). A connection failure
 * (timeout, DNS, refused) becomes PageFetchResult::failed() instead of a
 * thrown exception, exactly like every original command's own
 * try/catch(ConnectionException).
 */
final class HttpPageFetcher implements PageFetcher
{
    public function fetch(string $url, int $timeoutSeconds, string $userAgent): PageFetchResult
    {
        $start = microtime(true);

        try {
            $response = Http::timeout($timeoutSeconds)->withUserAgent($userAgent)->get($url);
        } catch (ConnectionException) {
            return PageFetchResult::failed($this->elapsedMs($start));
        }

        return PageFetchResult::make(
            $response->status(),
            $response->headers(),
            (string) $response->body(),
            $this->elapsedMs($start),
        );
    }

    private function elapsedMs(float $start): float
    {
        return round((microtime(true) - $start) * 1000, 1);
    }
}
