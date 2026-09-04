<?php

namespace App\Console\Commands;

use App\Services\DataForSeoService;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly link gap: the domains that link to the competitors but not to us,
 * ranked by how many competitors they link to and their own strength — the
 * outreach list (directories, local press, associations, suppliers). Also
 * refreshes which domains link to us. ~$0.024 per 100 referring domains.
 */
class SeoBacklinkGap extends Command
{
    protected $signature = 'seo:backlink-gap {--competitors=8} {--per-domain=100} {--budget=1}';

    protected $description = 'Domains linking to competitors but not to us (DataForSEO backlinks) into seo_backlink_prospects';

    public function handle(DataForSeoService $dfs): int
    {
        if (! $dfs->isConfigured() || ! Schema::hasTable('seo_backlink_prospects')) {
            $this->comment('DataForSEO not configured or table missing — skipping.');

            return self::SUCCESS;
        }
        $ours = preg_replace('#^https?://(www\.)?#', '', rtrim((string) config('app.url'), '/')) ?: 'gs.construction';
        $competitors = SeoDomainOverview::competitorDomains((int) $this->option('competitors'));
        $estimate = (count($competitors) + 3) * 0.025;
        $balance = $dfs->balance();
        if ($balance !== null && $balance < $estimate) {
            $this->error(sprintf('DataForSEO balance $%.2f cannot cover this run ($%.2f).', $balance, $estimate));

            return self::FAILURE;
        }

        $oursLinks = collect($dfs->referringDomains($ours, 300))->pluck('domain')->flip();
        $this->line("  {$ours}: " . $oursLinks->count() . ' referring domains');

        $prospects = [];
        foreach ($competitors as $c) {
            if ($dfs->spent() >= (float) $this->option('budget')) {
                $this->warn('Budget reached.');
                break;
            }
            $rows = $dfs->referringDomains($c, (int) $this->option('per-domain'));
            $this->line("  {$c}: " . count($rows) . ' referring domains');
            foreach ($rows as $r) {
                $d = $r['domain'];
                if ($d === $ours || $d === $c || str_ends_with($d, '.' . $c)) {
                    continue;
                }
                $p = $prospects[$d] ?? ['domain' => $d, 'rank' => 0, 'links_to' => [], 'platform' => $r['platform']];
                $p['rank'] = max($p['rank'], $r['rank']);
                $p['links_to'][$c] = $r['backlinks'];
                $prospects[$d] = $p;
            }
        }

        $siteId = \App\Models\Site::current()?->id;
        $n = 0;
        foreach ($prospects as $d => $p) {
            Tenancy::table('seo_backlink_prospects')->updateOrInsert(
                ['site_id' => $siteId, 'domain' => mb_substr($d, 0, 191)],
                [
                    'rank' => $p['rank'],
                    'links_to' => json_encode($p['links_to']),
                    'competitor_count' => count($p['links_to']),
                    'links_to_us' => isset($oursLinks[$d]),
                    'platform_type' => $p['platform'] ? mb_substr((string) $p['platform'], 0, 60) : null,
                    'seen_at' => now(),
                    'updated_at' => now(), 'created_at' => now(),
                ]
            );
            $n++;
        }
        Cache::forget(Tenancy::cacheKey('seo_reports_dataforseo_v1'));
        $gap = collect($prospects)->filter(fn ($p, $d) => ! isset($oursLinks[$d]) && count($p['links_to']) >= 2)->count();
        $this->info(sprintf('%d prospect domains recorded; %d link to 2+ competitors and not to us. Spent $%.3f.', $n, $gap, $dfs->spent()));

        return self::SUCCESS;
    }
}
