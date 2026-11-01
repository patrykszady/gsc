<?php

namespace App\Support\Seo;

use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Map-pack leaders (geo-grid) then organic page-one domains (the competitor
 * discovery file), deduplicated and filtered through CompetitorFilter::keep()
 * — the shared source every SEO intel family reads through
 * App\Services\Seo\Intel\IntelSource::competitorDomains() (LabsSource's
 * fallback, BacklinksSource, DomainAnalyticsSource, ...).
 *
 * Kit SEO tools port (0.19.1, 2026-11-01): extracted from the retired
 * App\Console\Commands\SeoDomainOverview, whose weekly run is now the kit's
 * SsSystems\Platform\Seo\Visibility\Console\DomainOverviewCommand — a
 * different, simpler source (SsSystems\Platform\Seo\Visibility\
 * CompetitorDomains, built from the site profile + seo_rank_snapshots).
 * Intel's own competitor sourcing stays exactly as it was: map-pack hosts,
 * then the discovery file. SeoDomainOverview::shareOfVoiceDomains() (the
 * owner-curated /compare companies first, read by that command's own weekly
 * footprint list) is NOT carried over — nothing calls it once that command
 * is gone, and the kit's DomainOverviewCommand has its own domain selection.
 */
final class CompetitorDomainSources
{
    public static function competitorDomains(int $limit): array
    {
        $domains = self::mapPackHosts()->concat(self::discoveredHosts());

        return collect(CompetitorFilter::keep($domains))->take($limit)->values()->all();
    }

    /** @return Collection<int, string> */
    protected static function mapPackHosts(): Collection
    {
        if (! Schema::hasTable('map_pack_competitors')) {
            return collect();
        }

        return Tenancy::table('map_pack_competitors')->whereNotNull('host')->where('pack_points', '>', 0)
            ->select('host', DB::raw('SUM(pack_points) w'))->groupBy('host')->orderByDesc('w')->limit(30)->pluck('host');
    }

    /** @return Collection<int, string> */
    protected static function discoveredHosts(): Collection
    {
        $disc = Storage::disk('local')->exists('reports/competitor-discovery.json')
            ? json_decode((string) Storage::disk('local')->get('reports/competitor-discovery.json'), true)
            : null;

        return collect((array) ($disc['domains'] ?? []))->map(fn ($d) => $d['host'] ?? null)->filter()->values();
    }
}
