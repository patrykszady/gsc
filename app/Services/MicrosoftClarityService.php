<?php

namespace App\Services;

use App\Models\Site;
use App\Support\Seo\FrustratedPages;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MicrosoftClarityService
{
    protected ?string $lastError = null;

    /**
     * Clarity Data Export API supports only the last 1-3 days.
     */
    public const MAX_DAYS = 3;

    public function isConfigured(): bool
    {
        return filled(config('services.microsoft.clarity.project_id'))
            && filled(config('services.microsoft.clarity.api_token'));
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Fetch Clarity dashboard export metrics.
     *
     * Returns a single normalized snapshot row:
     * ['date' => 'YYYY-MM-DD', 'sessions' => int, ...]
     */
    public function fetchDailyMetrics(int $days = 28): ?array
    {
        $token = (string) config('services.microsoft.clarity.api_token');
        $baseUrl = rtrim((string) config('services.microsoft.clarity.base_url', 'https://www.clarity.ms/export-data/api/v1'), '/');

        if ($token === '') {
            $this->lastError = 'Clarity API token missing';

            return null;
        }

        $url = "{$baseUrl}/project-live-insights";

        // Clarity API only accepts 1..3 days.
        $days = max(1, min(self::MAX_DAYS, $days));

        // Bearer auth is required by Clarity Data Export API docs.
        $attempts = [
            [
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
            ],
        ];

        $query = [
            'numOfDays' => $days,
            // A stable dimension keeps response structure deterministic.
            'dimension1' => 'OS',
        ];

        $response = null;
        foreach ($attempts as $headers) {
            $resp = Http::timeout(45)
                ->acceptJson()
                ->withHeaders($headers)
                ->get($url, $query);

            if ($resp->successful()) {
                $response = $resp;
                break;
            }

            Log::warning('Clarity API call failed', [
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 500),
                'auth_header' => array_key_first($headers),
            ]);
        }

        if (! $response || ! $response->successful()) {
            $this->lastError = 'Clarity API request failed (check token/project id and API permissions).';

            return null;
        }

        $payload = $response->json();

        // Clarity payload shapes can vary; normalize robustly.
        if (! is_array($payload)) {
            $this->lastError = 'Unexpected Clarity payload shape.';

            return null;
        }

        // API returns an array of metric groups, each with rows broken out by `dimension1`.
        // Confirmed shapes (project-live-insights, dimension1=OS):
        //   Traffic         -> totalSessionCount, totalBotSessionCount, distinctUserCount, pagesPerSessionPercentage
        //   EngagementTime  -> totalTime, activeTime
        //   ScrollDepth     -> averageScrollDepth
        //   DeadClickCount  -> subTotal (count of dead clicks for that OS)
        //   RageClickCount  -> subTotal
        //   QuickbackClick  -> subTotal
        //   ExcessiveScroll, ScriptErrorCount, ErrorClickCount -> subTotal
        $summary = [
            'date' => now()->toDateString(),
            'sessions' => 0,
            'users' => 0,
            'pageviews' => 0,
            'scroll_depth' => 0.0,
            'active_time_seconds' => 0,
            'bounce_rate' => 0.0,
            'dead_clicks' => 0,
            'rage_clicks' => 0,
            'quickbacks' => 0,
            'script_errors' => 0,
            'error_clicks' => 0,
        ];

        // Track weighted scroll depth (avg scroll depth weighted by sessions per OS).
        $scrollWeightedSum = 0.0;
        $scrollWeightSessions = 0;
        // Pages-per-session is reported per-OS; use a session-weighted average.
        $ppsWeightedSum = 0.0;
        $ppsWeightSessions = 0;

        // Index sessions per OS from the Traffic group so we can weight other groups.
        $sessionsByOs = [];
        foreach ($payload as $g) {
            if (is_array($g) && strcasecmp((string) ($g['metricName'] ?? ''), 'Traffic') === 0) {
                foreach ($g['information'] ?? [] as $r) {
                    if (! is_array($r)) {
                        continue;
                    }
                    $os = (string) ($r['OS'] ?? $r['Browser'] ?? '');
                    $sessionsByOs[$os] = $this->toInt($r, ['totalSessionCount', 'sessionCount', 'sessions']);
                }
                break;
            }
        }

        foreach ($payload as $metricGroup) {
            if (! is_array($metricGroup)) {
                continue;
            }

            $metricName = (string) ($metricGroup['metricName'] ?? '');
            $infoRows = $metricGroup['information'] ?? [];
            if (! is_array($infoRows)) {
                continue;
            }

            foreach ($infoRows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $os = (string) ($row['OS'] ?? $row['Browser'] ?? '');
                $rowSessions = $sessionsByOs[$os] ?? $this->toInt($row, ['totalSessionCount', 'sessionCount', 'sessions']);

                switch (true) {
                    case strcasecmp($metricName, 'Traffic') === 0:
                        $sessions = $this->toInt($row, ['totalSessionCount', 'sessionCount', 'sessions']);
                        $summary['sessions'] += $sessions;
                        $summary['users'] += $this->toInt($row, ['distinctUserCount', 'distantUserCount', 'uniqueUsers', 'users']);
                        $pps = $this->toFloat($row, ['pagesPerSessionPercentage', 'pagesPerSession']);
                        if ($sessions > 0 && $pps > 0) {
                            $ppsWeightedSum += $pps * $sessions;
                            $ppsWeightSessions += $sessions;
                            $summary['pageviews'] += (int) round($pps * $sessions);
                        }
                        break;

                    case strcasecmp($metricName, 'EngagementTime') === 0:
                        // totalTime/activeTime are reported in seconds, per-OS totals.
                        $summary['active_time_seconds'] += $this->toInt($row, ['activeTime', 'engagementTime', 'activeTimeSeconds']);
                        break;

                    case strcasecmp($metricName, 'ScrollDepth') === 0:
                        $depth = $this->toFloat($row, ['averageScrollDepth', 'ScrollDepth', 'scrollDepth']);
                        if ($depth > 0 && $rowSessions > 0) {
                            $scrollWeightedSum += $depth * $rowSessions;
                            $scrollWeightSessions += $rowSessions;
                        }
                        break;

                    case strcasecmp($metricName, 'DeadClickCount') === 0:
                        $summary['dead_clicks'] += $this->toInt($row, ['subTotal', 'deadClickCount', 'deadClicks']);
                        break;

                    case strcasecmp($metricName, 'RageClickCount') === 0:
                        $summary['rage_clicks'] += $this->toInt($row, ['subTotal', 'rageClickCount', 'rageClicks']);
                        break;

                    case strcasecmp($metricName, 'QuickbackClick') === 0:
                        $summary['quickbacks'] += $this->toInt($row, ['subTotal', 'quickbackClick', 'quickbacks']);
                        break;

                    case strcasecmp($metricName, 'ScriptErrorCount') === 0:
                        $summary['script_errors'] += $this->toInt($row, ['subTotal', 'scriptErrorCount', 'scriptErrors']);
                        break;

                    case strcasecmp($metricName, 'ErrorClickCount') === 0:
                        $summary['error_clicks'] += $this->toInt($row, ['subTotal', 'errorClickCount', 'errorClicks']);
                        break;
                }
            }
        }

        if ($scrollWeightSessions > 0) {
            $summary['scroll_depth'] = round($scrollWeightedSum / $scrollWeightSessions, 4);
        }

        // Bounce rate isn't exposed by project-live-insights; leave 0.0 unless future endpoint provides it.

        return [$summary];
    }

    /**
     * The same signals as fetchDailyMetrics(), broken out per page.
     *
     * One request with the URL dimension, always for the last 24 hours, so
     * every daily run stores a bucket that does not overlap the one before —
     * unlike the site-wide sync, whose 3-day window is a rolling aggregate.
     * Costs one of the project's 10 daily requests.
     *
     * Clarity reports every host that carries the project's tag, and this
     * project's tag also loads on local dev, so http://127.0.0.1 shows up in
     * production's export. Only the site's own hosts are kept, and the
     * http/https/www/trailing-slash variants of a page are folded into one
     * row under its normalised path — which is also the form Search Console
     * pages are reduced to when the two are joined.
     *
     * @return list<array{date:string,path:string,sessions:int,rage_clicks:int,dead_clicks:int,quickbacks:int,script_errors:int,scroll_depth:float}>|null
     */
    public function fetchPageMetrics(): ?array
    {
        $payload = $this->requestInsights(['numOfDays' => 1, 'dimension1' => 'URL']);
        if ($payload === null) {
            return null;
        }

        $hosts = $this->siteHosts();
        $pages = [];

        foreach ($payload as $group) {
            if (! is_array($group)) {
                continue;
            }
            $metricName = (string) ($group['metricName'] ?? '');
            $rows = $group['information'] ?? [];
            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                // The dimension key comes back as "Url" for a request made with "URL".
                $path = $this->pathForSite($row['Url'] ?? $row['URL'] ?? null, $hosts);
                if ($path === null) {
                    continue;
                }

                $pages[$path] ??= [
                    'sessions' => 0, 'rage_clicks' => 0, 'dead_clicks' => 0, 'quickbacks' => 0,
                    'script_errors' => 0, 'scroll_sum' => 0.0, 'scroll_n' => 0,
                ];

                switch (true) {
                    case strcasecmp($metricName, 'Traffic') === 0:
                        // Human sessions: Clarity counts bots inside the total.
                        $total = $this->toInt($row, ['totalSessionCount', 'sessionCount', 'sessions']);
                        $bots = $this->toInt($row, ['totalBotSessionCount', 'botSessionCount']);
                        $pages[$path]['sessions'] += max(0, $total - $bots);
                        break;

                    case strcasecmp($metricName, 'ScrollDepth') === 0:
                        $depth = $this->toFloat($row, ['averageScrollDepth', 'ScrollDepth', 'scrollDepth']);
                        if ($depth > 0) {
                            $pages[$path]['scroll_sum'] += $depth;
                            $pages[$path]['scroll_n']++;
                        }
                        break;

                    case strcasecmp($metricName, 'DeadClickCount') === 0:
                        $pages[$path]['dead_clicks'] += $this->toInt($row, ['subTotal', 'deadClickCount', 'deadClicks']);
                        break;

                    case strcasecmp($metricName, 'RageClickCount') === 0:
                        $pages[$path]['rage_clicks'] += $this->toInt($row, ['subTotal', 'rageClickCount', 'rageClicks']);
                        break;

                    case strcasecmp($metricName, 'QuickbackClick') === 0:
                        $pages[$path]['quickbacks'] += $this->toInt($row, ['subTotal', 'quickbackClick', 'quickbacks']);
                        break;

                    case strcasecmp($metricName, 'ScriptErrorCount') === 0:
                        $pages[$path]['script_errors'] += $this->toInt($row, ['subTotal', 'scriptErrorCount', 'scriptErrors']);
                        break;
                }
            }
        }

        $date = now()->toDateString();
        $out = [];
        foreach ($pages as $path => $m) {
            $signals = $m['sessions'] + $m['rage_clicks'] + $m['dead_clicks'] + $m['quickbacks'] + $m['script_errors'];
            if ($signals === 0) {
                continue; // Traffic lists every URL it has ever seen; an all-zero day is not a row.
            }
            $out[] = [
                'date' => $date,
                'path' => $path,
                'sessions' => $m['sessions'],
                'rage_clicks' => $m['rage_clicks'],
                'dead_clicks' => $m['dead_clicks'],
                'quickbacks' => $m['quickbacks'],
                'script_errors' => $m['script_errors'],
                'scroll_depth' => $m['scroll_n'] > 0 ? round($m['scroll_sum'] / $m['scroll_n'], 2) : 0.0,
            ];
        }

        return $out;
    }

    /**
     * One project-live-insights request. Null (with lastError set) on any
     * failure; the raw metric-group array otherwise.
     *
     * @param  array<string, int|string>  $query
     * @return array<int, mixed>|null
     */
    protected function requestInsights(array $query): ?array
    {
        $token = (string) config('services.microsoft.clarity.api_token');
        $baseUrl = rtrim((string) config('services.microsoft.clarity.base_url', 'https://www.clarity.ms/export-data/api/v1'), '/');

        if ($token === '') {
            $this->lastError = 'Clarity API token missing';

            return null;
        }

        $resp = Http::timeout(45)
            ->acceptJson()
            ->withHeaders(['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'])
            ->get("{$baseUrl}/project-live-insights", $query);

        if (! $resp->successful()) {
            Log::warning('Clarity API call failed', [
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 500),
                'query' => $query,
            ]);
            $this->lastError = 'Clarity API request failed (check token/project id and API permissions).';

            return null;
        }

        $payload = $resp->json();
        if (! is_array($payload)) {
            $this->lastError = 'Unexpected Clarity payload shape.';

            return null;
        }

        return $payload;
    }

    /**
     * Hosts that count as this site, lowercased and without a leading www —
     * anything else in the export (local dev, a preview tunnel) is dropped.
     *
     * @return list<string>
     */
    protected function siteHosts(): array
    {
        $site = Site::current();
        $hosts = array_merge([(string) $site->primary_host], (array) $site->hosts);

        return array_values(array_unique(array_filter(array_map(
            fn ($h) => preg_replace('/^www\./', '', strtolower(trim((string) $h))),
            $hosts,
        ))));
    }

    /** @param  list<string>  $hosts */
    protected function pathForSite(mixed $url, array $hosts): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host)) {
            return null;
        }

        $host = preg_replace('/^www\./', '', strtolower($host));
        if (! in_array($host, $hosts, true)) {
            return null;
        }

        return FrustratedPages::normalisePath($url);
    }

    protected function toInt(array $row, array $keys): int
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && is_numeric($row[$k])) {
                return (int) $row[$k];
            }
        }

        return 0;
    }

    protected function toFloat(array $row, array $keys): float
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && is_numeric($row[$k])) {
                return round((float) $row[$k], 4);
            }
        }

        return 0.0;
    }
}
