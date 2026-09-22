<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\DataForSeoService;
use App\Support\Seo\CompetitorFilter;
use App\Support\Seo\DataForSeoBudget;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Weekly organic share of voice: how many Google keywords we and each
 * competitor rank for, by position band, plus estimated traffic and the
 * backlink profile (domain rank, referring domains). One row per domain per
 * week in seo_domain_overviews — the trend the SEO page charts.
 * ~$0.036 per domain (overview + backlink summary); ~$1.10 a week for us
 * plus the 26 curated competitors and a few discovered ones.
 */
class SeoDomainOverview extends Command
{
    protected $signature = 'seo:domain-overview {--competitors=30 : Competitor domains to include} {--budget=2}';

    protected $description = 'Weekly organic footprint + backlink profile for us and the competitors (DataForSEO) into seo_domain_overviews';

    public function handle(DataForSeoService $dfs): int
    {
        if (! $dfs->isConfigured() || ! Schema::hasTable('seo_domain_overviews')) {
            $this->comment('DataForSEO not configured or table missing — skipping.');

            return self::SUCCESS;
        }
        $ours = preg_replace('#^https?://(www\.)?#', '', rtrim((string) config('app.url'), '/')) ?: 'gs.construction';
        $domains = collect([$ours])->concat(self::shareOfVoiceDomains((int) $this->option('competitors')))->unique()->values();

        // Estimate-vs-budget was missing here (unlike every sibling command),
        // and the balance check let a failed balance() (null) through
        // silently; the shared guard fails closed on both.
        $estimate = $domains->count() * 0.04;
        $guard = new DataForSeoBudget;
        if ($msg = $guard->precheck($estimate, (float) $this->option('budget'), $dfs->balance(), $dfs->getLastError())) {
            $this->error($msg);

            return self::FAILURE;
        }

        $siteId = Site::current()?->id;
        $today = now()->toDateString();
        foreach ($domains as $domain) {
            if ($dfs->spent() >= (float) $this->option('budget')) {
                $this->warn('Budget reached.');
                break;
            }
            $o = $dfs->domainRankOverview($domain);
            $b = $dfs->backlinkSummary($domain);
            if ($o === null && $b === null) {
                $this->line("  {$domain}: no data (".($dfs->getLastError() ?? '?').')');
                $guard->record(false);

                continue;
            }
            $guard->record(true);
            Tenancy::table('seo_domain_overviews')->updateOrInsert(
                ['site_id' => $siteId, 'domain' => $domain, 'date' => $today],
                [
                    'is_us' => $domain === $ours,
                    'pos_1' => $o['pos_1'] ?? 0, 'pos_2_3' => $o['pos_2_3'] ?? 0, 'pos_4_10' => $o['pos_4_10'] ?? 0, 'pos_11_20' => $o['pos_11_20'] ?? 0,
                    'keywords_total' => $o['count'] ?? 0, 'etv' => $o['etv'] ?? 0, 'is_new' => $o['is_new'] ?? 0, 'is_lost' => $o['is_lost'] ?? 0,
                    'backlinks' => $b['backlinks'] ?? null, 'referring_domains' => $b['referring_domains'] ?? null, 'domain_rank' => $b['rank'] ?? null,
                    'updated_at' => now(), 'created_at' => now(),
                ]
            );
            $this->line(sprintf('  %-34s top10=%3d  total=%4d  etv=%6.0f  refdomains=%s', $domain, ($o['pos_1'] ?? 0) + ($o['pos_2_3'] ?? 0) + ($o['pos_4_10'] ?? 0), $o['count'] ?? 0, $o['etv'] ?? 0, $b['referring_domains'] ?? '-'));
        }
        Cache::forget(Tenancy::cacheKey('seo_reports_dataforseo_v1'));
        if ($guard->allFailed()) {
            $this->error('Every domain failed — DataForSEO may be down or misconfigured.');

            return self::FAILURE;
        }
        $this->info(sprintf('Done. Spent $%.3f.', $dfs->spent()));

        return self::SUCCESS;
    }

    /**
     * The "vs. competitors" set for the weekly footprint, in the order the
     * slots are filled:
     *
     *  1. the owner-curated /compare companies (config/competitors.php) —
     *     the businesses the site publicly positions against, so the card
     *     on the SEO screen shows the same names the public comparison
     *     pages do;
     *  2. organic page-one domains (the competitor discovery file, when one exists), the feed the SEO
     *     screen's "Competitor discovery" list already shows;
     *  3. map-pack leaders (geo-grid) last.
     *
     * competitorDomains() below used to feed this run, and it puts the map
     * pack first: with a limit of ten that filled every slot with hyper-local
     * map-pack hosts and never let a curated or discovered competitor in —
     * the card showed ten names the owner had never heard of while /compare
     * named twenty-six he chose. That order is right for the other intel
     * families (which is why competitorDomains() is unchanged); it is wrong
     * for a card whose whole point is "us against the businesses we compete
     * with".
     *
     * @return array<int, string>
     */
    public static function shareOfVoiceDomains(int $limit): array
    {
        $domains = collect(CompetitorFilter::knownLocalHosts())
            ->concat(self::discoveredHosts())
            ->concat(self::mapPackHosts());

        return collect(CompetitorFilter::keep($domains))->take($limit)->values()->all();
    }

    /**
     * Map-pack leaders (geo-grid) then organic page-one domains (the competitor discovery file
     * discovery), deduplicated and filtered through CompetitorFilter::keep()
     * — the shared source every other family reads through
     * IntelSource::competitorDomains().
     */
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
