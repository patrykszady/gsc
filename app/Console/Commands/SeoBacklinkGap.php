<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\DataForSeoService;
use App\Support\Seo\DataForSeoBudget;
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
        $guard = new DataForSeoBudget;
        if ($msg = $guard->precheck($estimate, (float) $this->option('budget'), $dfs->balance(), $dfs->getLastError())) {
            $this->error($msg);

            return self::FAILURE;
        }

        $oursLinks = collect($dfs->referringDomains($ours, 300))->pluck('domain')->flip();
        $this->line("  {$ours}: ".$oursLinks->count().' referring domains');

        $prospects = [];
        // A fresh instance per competitor, not the shared $dfs, because
        // DataForSeoService::$lastError is set-only — it never clears — so
        // comparing it to its value before THIS call ("unchanged" = success)
        // misreads a second call that fails with the same error text as a
        // success. A new instance starts with lastError = null, so any
        // non-null value after the call belongs to this call alone. Its own
        // cost is folded into $probeSpent since $dfs->spent() never sees it.
        $probeSpent = 0.0;
        foreach ($competitors as $c) {
            if ($dfs->spent() + $probeSpent >= (float) $this->option('budget')) {
                $this->warn('Budget reached.');
                break;
            }
            $call = new DataForSeoService;
            $rows = $call->referringDomains($c, (int) $this->option('per-domain'));
            $guard->record($rows !== [] || $call->getLastError() === null);
            $probeSpent += $call->spent();
            $this->line("  {$c}: ".count($rows).' referring domains');
            foreach ($rows as $r) {
                $d = $r['domain'];
                if ($d === $ours || $d === $c || str_ends_with($d, '.'.$c)) {
                    continue;
                }
                // Free-host spam networks ("housesbathroom.web.app", blogspot farms) are not prospects.
                if (preg_match('/\.(web\.app|blogspot\.com|wordpress\.com|weebly\.com|wixsite\.com|github\.io|netlify\.app|vercel\.app|pages\.dev|firebaseapp\.com)$/', $d)) {
                    continue;
                }
                $p = $prospects[$d] ?? ['domain' => $d, 'rank' => 0, 'links_to' => [], 'platform' => $r['platform'], 'spam' => null];
                $p['rank'] = max($p['rank'], $r['rank']);
                $p['spam'] = max((int) $p['spam'], (int) ($r['spam_score'] ?? 0));
                $p['links_to'][$c] = $r['backlinks'];
                $prospects[$d] = $p;
            }
        }

        $siteId = Site::current()?->id;
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
                    'spam_score' => $p['spam'],
                    'seen_at' => now(),
                    'updated_at' => now(), 'created_at' => now(),
                ]
            );
            $n++;
        }
        Cache::forget(Tenancy::cacheKey('seo_reports_dataforseo_v1'));
        if ($guard->allFailed()) {
            $this->error('Every competitor failed — DataForSEO may be down or misconfigured.');

            return self::FAILURE;
        }
        $gap = collect($prospects)->filter(fn ($p, $d) => ! isset($oursLinks[$d]) && count($p['links_to']) >= 2 && (int) $p['spam'] < 30 && count($p['links_to']) < 6)->count();
        $this->info(sprintf('%d prospect domains recorded; %d link to 2+ competitors and not to us. Spent $%.3f.', $n, $gap, $dfs->spent() + $probeSpent));

        return self::SUCCESS;
    }
}
