<?php

namespace App\Console\Commands;

use App\Services\Seo\CompetitorSiteFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read the homepages of the businesses that own the map pack (recorded by
 * seo:map-pack-grid into map_pack_competitors) so the SEO page can show
 * what they offer and which of our towns they name. Analysis only — see
 * CompetitorSiteFetcher. One page per competitor, re-read monthly.
 */
class SeoMapPackCompetitors extends Command
{
    protected $signature = 'seo:map-pack-competitors {--limit=20 : Competitor sites to read this run} {--refresh : Re-read sites fetched within the last 30 days}';

    protected $description = 'Read map-pack competitors\' homepages (structure, services, towns) for the SEO page';

    public function handle(CompetitorSiteFetcher $fetcher): int
    {
        $q = \App\Support\Tenancy::table('map_pack_competitors')
            ->whereNotNull('url')
            ->where('pack_points', '>', 0)
            ->orderByDesc('pack_points');
        if (! $this->option('refresh')) {
            $q->where(fn ($w) => $w->whereNull('site_fetched_at')->orWhere('site_fetched_at', '<', now()->subDays(30)));
        }

        $seen = [];
        $read = 0;
        foreach ($q->limit((int) $this->option('limit') * 3)->get() as $row) {
            $host = $row->host ?: parse_url((string) $row->url, PHP_URL_HOST);
            if (! $host || isset($seen[$host])) {
                continue;
            }
            $seen[$host] = true;
            if ($read >= (int) $this->option('limit')) {
                break;
            }

            $data = $fetcher->read((string) $row->url) ?? ['site_fetched_at' => now()];
            $data = array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $data);
            \App\Support\Tenancy::table('map_pack_competitors')->where('host', $host)->update($data + ['updated_at' => now()]);
            $read++;
            $this->line("  {$row->name} — " . (isset($data['site_title']) ? 'read' : 'unreachable'));
            usleep(700000);
        }

        $this->info("Read {$read} competitor site(s).");

        return self::SUCCESS;
    }
}
