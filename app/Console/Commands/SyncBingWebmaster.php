<?php

namespace App\Console\Commands;

use App\Models\BingTrafficStat;
use App\Services\BingWebmasterService;
use Illuminate\Console\Command;

class SyncBingWebmaster extends Command
{
    protected $signature = 'seo:bing-sync {--dry-run}';

    protected $description = 'Sync Bing Webmaster Tools query stats (free, API-key auth)';

    public function handle(BingWebmasterService $svc): int
    {
        if (! $svc->isConfigured()) {
            $this->error('Bing not configured. Set BING_WEBMASTER_API_KEY in .env.');
            return self::FAILURE;
        }

        $rows = $svc->fetchQueryStats();
        if ($rows === null) {
            $this->error('Fetch failed.');
            return self::FAILURE;
        }

        $count = count($rows);
        $this->info("Fetched {$count} rows from Bing WMT");

        if ((bool) $this->option('dry-run')) {
            foreach (array_slice($rows, 0, 10) as $r) {
                $this->line(json_encode($r));
            }
            return self::SUCCESS;
        }

        $upserts = 0;
        foreach ($rows as $r) {
            if (! $r['query']) {
                continue;
            }
            $date = $r['date'];
            $siteUrl = mb_substr((string) $r['site_url'], 0, 191);
            $query = mb_substr((string) $r['query'], 0, 500);
            $dimHash = sha1("{$date}|{$siteUrl}|{$query}");

            BingTrafficStat::updateOrCreate(
                ['dim_hash' => $dimHash],
                [
                    'date' => $date,
                    'site_url' => $siteUrl,
                    'query' => $query,
                    'impressions' => $r['impressions'],
                    'clicks' => $r['clicks'],
                    'position' => $r['position'],
                    'dim_hash' => $dimHash,
                ],
            );
            $upserts++;
        }
        $this->info("Upserted {$upserts} rows.");
        return self::SUCCESS;
    }
}
