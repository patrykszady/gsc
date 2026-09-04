<?php

namespace App\Console\Commands;

use App\Services\DataForSeoService;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Weekly organic share of voice: how many Google keywords we and each
 * competitor rank for, by position band, plus estimated traffic and the
 * backlink profile (domain rank, referring domains). One row per domain per
 * week in seo_domain_overviews — the trend the SEO page charts.
 * ~$0.036 per domain (overview + backlink summary), ~$0.40 a week for 11.
 */
class SeoDomainOverview extends Command
{
    protected $signature = 'seo:domain-overview {--competitors=10 : Competitor domains to include} {--budget=1}';

    protected $description = 'Weekly organic footprint + backlink profile for us and the competitors (DataForSEO) into seo_domain_overviews';

    public function handle(DataForSeoService $dfs): int
    {
        if (! $dfs->isConfigured() || ! Schema::hasTable('seo_domain_overviews')) {
            $this->comment('DataForSEO not configured or table missing — skipping.');

            return self::SUCCESS;
        }
        $ours = preg_replace('#^https?://(www\.)?#', '', rtrim((string) config('app.url'), '/')) ?: 'gs.construction';
        $domains = collect([$ours])->concat(self::competitorDomains((int) $this->option('competitors')))->unique()->values();

        $estimate = $domains->count() * 0.04;
        $balance = $dfs->balance();
        if ($balance !== null && $balance < $estimate) {
            $this->error(sprintf('DataForSEO balance $%.2f cannot cover this run ($%.2f).', $balance, $estimate));

            return self::FAILURE;
        }

        $siteId = \App\Models\Site::current()?->id;
        $today = now()->toDateString();
        foreach ($domains as $domain) {
            if ($dfs->spent() >= (float) $this->option('budget')) {
                $this->warn('Budget reached.');
                break;
            }
            $o = $dfs->domainRankOverview($domain);
            $b = $dfs->backlinkSummary($domain);
            if ($o === null && $b === null) {
                $this->line("  {$domain}: no data (" . ($dfs->getLastError() ?? '?') . ')');
                continue;
            }
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
        $this->info(sprintf('Done. Spent $%.3f.', $dfs->spent()));

        return self::SUCCESS;
    }

    /** Map-pack leaders (Local Falcon) then organic page-one domains (Brave discovery), deduplicated. */
    public static function competitorDomains(int $limit): array
    {
        $domains = collect();
        if (Schema::hasTable('local_falcon_competitors')) {
            $domains = $domains->concat(Tenancy::table('local_falcon_competitors')->whereNotNull('host')->where('pack_points', '>', 0)
                ->select('host', DB::raw('SUM(pack_points) w'))->groupBy('host')->orderByDesc('w')->limit(30)->pluck('host'));
        }
        $disc = Storage::disk('local')->exists('reports/competitor-discovery.json') ? json_decode((string) Storage::disk('local')->get('reports/competitor-discovery.json'), true) : null;
        foreach ((array) ($disc['domains'] ?? []) as $d) {
            $domains->push($d['host']);
        }

        return $domains->map(fn ($h) => preg_replace('/^www\./', '', mb_strtolower((string) $h)))->filter()->unique()->take($limit)->values()->all();
    }
}
