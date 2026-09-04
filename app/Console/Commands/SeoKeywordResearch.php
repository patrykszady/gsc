<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Services\DataForSeoService;
use App\Services\Seo\SeoAutopilotService;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Weekly keyword + competitor research into seo_keywords (DataForSEO):
 *
 *  1. Universe: every Search Console query with impressions, the generated
 *     town × service phrases for every area we serve, and the keywords the
 *     map-pack leaders (Local Falcon) and organic page-one competitors (Brave
 *     discovery) rank for. Remodeling-ish terms only.
 *  2. Search volume for the universe (one task per 1,000 keywords).
 *  3. Our own standing from gsc_query_metrics (position, impressions, clicks).
 *  4. An opportunity score: volume × how far we are from page 1 × whether a
 *     competitor already proves the intent — the autopilot reads this to
 *     create pages, open town service pages, rewrite titles and refresh copy.
 *
 * A balance guard keeps a run inside --budget and refuses to start when the
 * account can't cover it, so an empty account produces a clear message, not
 * half a run.
 */
class SeoKeywordResearch extends Command
{
    protected $signature = 'seo:keyword-research
        {--budget=3 : Max USD to spend this run}
        {--competitors=15 : Competitor domains to pull ranked keywords for}
        {--ideas : Also pull keyword ideas around each service (adds ~$0.10)}
        {--dry-run : Build the universe and report what would be fetched, spend nothing}';

    protected $description = 'Keyword + competitor research via DataForSEO into seo_keywords (volume, our position, competitor coverage, opportunity)';

    private const REMODELING = ['remodel', 'renovat', 'kitchen', 'bathroom', 'bath ', 'basement', 'addition', 'contractor', 'cabinet', 'countertop', 'shower', 'tile', 'mudroom', 'home improvement', 'design build', 'design-build'];

    public function handle(DataForSeoService $dfs, SeoAutopilotService $engine): int
    {
        if (! $dfs->isConfigured()) {
            $this->comment('DataForSEO not configured — skipping.');

            return self::SUCCESS;
        }
        if (! Schema::hasTable('seo_keywords')) {
            $this->error('seo_keywords table missing — run migrations.');

            return self::FAILURE;
        }

        $budget = (float) $this->option('budget');
        $dry = (bool) $this->option('dry-run');

        // ---- 1. universe -------------------------------------------------
        $universe = [];  // keyword => sources[]
        $add = function (string $kw, string $source) use (&$universe): void {
            $kw = trim(preg_replace('/\s+/', ' ', mb_strtolower($kw)) ?? '');
            if ($kw === '' || mb_strlen($kw) > 120 || ! $this->remodelingish($kw)) {
                return;
            }
            $universe[$kw][$source] = true;
        };

        $since = now()->subDays(90)->toDateString();
        $ours = [];
        if (Schema::hasTable('gsc_query_metrics')) {
            $rows = Tenancy::table('gsc_query_metrics')->where('date', '>=', now()->subDays(28)->toDateString())
                ->where('query', 'not like', '%gs construction%')
                ->groupBy('query')->selectRaw('query, SUM(impressions) imp, SUM(clicks) clicks, SUM(position*impressions)/NULLIF(SUM(impressions),0) pos')->get();
            foreach ($rows as $r) {
                $add((string) $r->query, 'gsc');
                $ours[mb_strtolower((string) $r->query)] = ['imp' => (int) $r->imp, 'clicks' => (int) $r->clicks, 'pos' => $r->pos !== null ? round((float) $r->pos, 1) : null];
            }
        }

        $services = ['kitchen remodeling', 'bathroom remodeling', 'home remodeling', 'basement remodeling', 'home additions', 'remodeling contractor', 'kitchen renovation', 'bathroom renovation'];
        foreach (AreaServed::query()->pluck('city') as $city) {
            $city = trim((string) $city);
            foreach ($services as $svc) {
                $add("{$svc} {$city}", 'town_service');
                $add("{$city} {$svc}", 'town_service');
            }
        }

        // ---- competitors: map-pack leaders + organic page-one domains ----
        $domains = collect();
        if (Schema::hasTable('local_falcon_competitors')) {
            $domains = $domains->concat(Tenancy::table('local_falcon_competitors')->whereNotNull('host')->where('pack_points', '>', 0)
                ->select('host', DB::raw('SUM(pack_points) w'))->groupBy('host')->orderByDesc('w')->limit(30)->pluck('host'));
        }
        $disc = Storage::disk('local')->exists('reports/competitor-discovery.json') ? json_decode((string) Storage::disk('local')->get('reports/competitor-discovery.json'), true) : null;
        foreach ((array) ($disc['domains'] ?? []) as $d) {
            $domains->push($d['host']);
        }
        $domains = $domains->map(fn ($h) => preg_replace('/^www\./', '', mb_strtolower((string) $h)))->filter()->unique()->take((int) $this->option('competitors'))->values();

        $this->info(sprintf('Universe: %d keywords (%d from Search Console); %d competitor domains.', count($universe), count($ours), $domains->count()));

        // ---- cost estimate + balance guard -------------------------------
        $estimate = ceil(count($universe) / 1000) * 0.08 + $domains->count() * 0.03 + ($this->option('ideas') ? count($services) * 0.02 : 0);
        $balance = $dfs->balance();
        $this->line(sprintf('Estimated cost: $%.2f · budget: $%.2f · balance: %s', $estimate, $budget, $balance === null ? 'unknown' : '$' . number_format($balance, 2)));
        if ($dry) {
            $this->line('Dry run — nothing fetched. Domains: ' . $domains->implode(', '));

            return self::SUCCESS;
        }
        if ($estimate > $budget) {
            $this->error("Estimated cost exceeds --budget; raise it or narrow --competitors.");

            return self::FAILURE;
        }
        if ($balance !== null && $balance < $estimate) {
            $this->error(sprintf('DataForSEO balance $%.2f cannot cover this run ($%.2f). Top up at app.dataforseo.com, then re-run.', $balance, $estimate));

            return self::FAILURE;
        }

        // ---- 2. competitor ranked keywords -------------------------------
        $competitorHits = []; // keyword => [domain => position]
        foreach ($domains as $domain) {
            if ($dfs->spent() >= $budget) {
                $this->warn('Budget reached before all competitors were pulled.');
                break;
            }
            $rows = $dfs->rankedKeywords($domain, 300);
            $n = 0;
            foreach ($rows as $r) {
                if (! $this->remodelingish($r['keyword'])) {
                    continue;
                }
                $add($r['keyword'], 'competitor');
                $competitorHits[$r['keyword']][$domain] = $r['position'];
                $universe[$r['keyword']]['__vol'] = max($universe[$r['keyword']]['__vol'] ?? 0, $r['volume']);
                $universe[$r['keyword']]['__diff'] = $r['difficulty'] ?? ($universe[$r['keyword']]['__diff'] ?? null);
                $n++;
            }
            $this->line("  {$domain}: {$n} remodeling keywords" . ($dfs->getLastError() ? " ({$dfs->getLastError()})" : ''));
        }

        // ---- 2b. ideas (optional) ----------------------------------------
        if ($this->option('ideas') && $dfs->spent() < $budget) {
            foreach ($dfs->keywordIdeas($services, 300) as $r) {
                $add($r['keyword'], 'ideas');
                $universe[$r['keyword']]['__vol'] = max($universe[$r['keyword']]['__vol'] ?? 0, $r['volume']);
            }
        }

        // ---- 3. volumes for everything without one -----------------------
        $need = array_keys(array_filter($universe, fn ($v) => ! isset($v['__vol'])));
        $volumes = [];
        foreach (array_chunk($need, 1000) as $chunk) {
            if ($dfs->spent() >= $budget) {
                $this->warn('Budget reached before all volumes were fetched.');
                break;
            }
            $volumes += $dfs->searchVolume($chunk);
        }

        // ---- 4. write ----------------------------------------------------
        $siteId = \App\Models\Site::current()?->id;
        $written = 0;
        foreach ($universe as $kw => $meta) {
            $vol = $meta['__vol'] ?? ($volumes[$kw]['volume'] ?? null);
            if ($vol === null && ! isset($ours[$kw])) {
                continue; // nothing known about it
            }
            $class = $engine->classify($kw);
            $mine = $ours[$kw] ?? null;
            $hits = $competitorHits[$kw] ?? [];
            $opp = $this->opportunity((int) ($vol ?? 0), $mine['pos'] ?? null, $hits !== []);
            Tenancy::table('seo_keywords')->updateOrInsert(
                ['site_id' => $siteId, 'keyword' => mb_substr($kw, 0, 191)],
                [
                    'volume' => $vol,
                    'cpc' => $volumes[$kw]['cpc'] ?? null,
                    'competition' => $volumes[$kw]['competition'] ?? null,
                    'difficulty' => $meta['__diff'] ?? null,
                    'service' => $class[0] ?? null,
                    'city' => $class[1] ?? null,
                    'modifier' => $class[2] ?? null,
                    'sources' => json_encode(array_keys(array_filter($meta, fn ($v, $k) => ! str_starts_with($k, '__'), ARRAY_FILTER_USE_BOTH))),
                    'competitor_domains' => $hits ? json_encode($hits) : null,
                    'competitor_best_position' => $hits ? min($hits) : null,
                    'our_position' => $mine['pos'] ?? null,
                    'our_impressions' => $mine['imp'] ?? null,
                    'our_clicks' => $mine['clicks'] ?? null,
                    'opportunity' => $opp,
                    'researched_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $written++;
        }

        Cache::forget(Tenancy::cacheKey('seo.area.service_demand'));
        Cache::forget(Tenancy::cacheKey('seo_reports_keywords_v1'));
        $this->info(sprintf('Wrote %d keywords. Spent $%.3f.', $written, $dfs->spent()));

        return self::SUCCESS;
    }

    private function remodelingish(string $kw): bool
    {
        foreach (self::REMODELING as $w) {
            if (str_contains($kw, $w)) {
                return true;
            }
        }

        return false;
    }

    /**
     * volume × distance-from-page-1 × proof. A term we already rank 1–3 for is
     * no opportunity; one a competitor ranks for that we are absent from is the
     * strongest signal there is real, winnable intent.
     */
    private function opportunity(int $volume, ?float $ourPos, bool $competitorRanks): float
    {
        if ($volume <= 0) {
            return 0.0;
        }
        $distance = $ourPos === null ? 1.0 : ($ourPos <= 3 ? 0.0 : min(1.0, ($ourPos - 3) / 17));
        $proof = $competitorRanks ? 1.3 : 1.0;

        return round($volume * $distance * $proof, 2);
    }
}
