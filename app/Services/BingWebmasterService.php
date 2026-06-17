<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Bing Webmaster Tools API (free, simple API key auth).
 *
 * https://learn.microsoft.com/en-us/bingwebmaster/getting-access
 * https://learn.microsoft.com/en-us/dotnet/api/microsoft.bing.webmaster.api.interfaces.iwebmasterapi.getrankandtrafficstats
 */
class BingWebmasterService
{
    protected const API_BASE = 'https://ssl.bing.com/webmaster/api.svc/json';

    public function isConfigured(): bool
    {
        return ! empty(config('services.bing.webmaster_api_key'))
            && ! empty(config('services.bing.site_url'));
    }

    /**
     * Returns array of rows: [['date'=>'YYYY-MM-DD','query'=>...,'impressions','clicks','position'], ...]
     */
    public function fetchQueryStats(?string $siteUrl = null): ?array
    {
        $siteUrl ??= config('services.bing.site_url');
        $apiKey = config('services.bing.webmaster_api_key');

        $resp = Http::timeout(45)->get(self::API_BASE . '/GetQueryStats', [
            'siteUrl' => $siteUrl,
            'apikey' => $apiKey,
        ]);

        if (! $resp->successful()) {
            Log::warning('Bing WMT: GetQueryStats failed', [
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 500),
            ]);
            return null;
        }

        $data = $resp->json('d', []);
        $out = [];
        foreach ($data as $row) {
            $date = $this->parseMsDate($row['Date'] ?? null);
            if (! $date) {
                continue;
            }
            $out[] = [
                'date' => $date,
                'site_url' => $siteUrl,
                'query' => (string) ($row['Query'] ?? ''),
                'impressions' => (int) ($row['Impressions'] ?? 0),
                'clicks' => (int) ($row['Clicks'] ?? 0),
                'position' => (float) ($row['AvgImpressionPosition']
                    ?? $row['AvgClickPosition']
                    ?? 0),
            ];
        }

        return $out;
    }

    /**
     * True site-wide daily traffic totals (impressions/clicks), not bucketed by
     * query. GetQueryStats omits clicks/impressions from anonymized/aggregated
     * queries, so its per-day sums under-report; this endpoint returns the real
     * daily figures shown in the Bing Webmaster dashboard.
     *
     * Returns: [['date'=>'YYYY-MM-DD','site_url'=>...,'impressions'=>int,'clicks'=>int], ...]
     *
     * @return array<int,array{date:string,site_url:string,impressions:int,clicks:int}>|null
     */
    public function fetchRankAndTrafficStats(?string $siteUrl = null): ?array
    {
        $siteUrl ??= config('services.bing.site_url');
        $apiKey = config('services.bing.webmaster_api_key');

        $resp = Http::timeout(45)->get(self::API_BASE . '/GetRankAndTrafficStats', [
            'siteUrl' => $siteUrl,
            'apikey' => $apiKey,
        ]);

        if (! $resp->successful()) {
            Log::warning('Bing WMT: GetRankAndTrafficStats failed', [
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 500),
            ]);
            return null;
        }

        $data = $resp->json('d', []);
        $out = [];
        foreach ($data as $row) {
            $date = $this->parseMsDate($row['Date'] ?? null);
            if (! $date) {
                continue;
            }
            $out[] = [
                'date' => $date,
                'site_url' => $siteUrl,
                'impressions' => (int) ($row['Impressions'] ?? 0),
                'clicks' => (int) ($row['Clicks'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Bing returns dates like "/Date(1747353600000)/".
     */
    protected function parseMsDate(?string $raw): ?string
    {
        if (! $raw || ! preg_match('/Date\((\d+)/', $raw, $m)) {
            return null;
        }
        return date('Y-m-d', (int) ($m[1] / 1000));
    }
}
