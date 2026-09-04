<?php

namespace App\Services\Seo\Intel\Sources;

use App\Services\DataForSeoService;
use App\Services\Seo\Intel\Finding;
use App\Services\Seo\Intel\IntelSource;
use App\Services\Seo\Intel\Snapshot;

/**
 * Backlinks family: our link profile and its movement, plus how fast the
 * competitors are building theirs, so a lost or broken link is noticed the
 * week it happens instead of months later when rankings have already slid.
 *
 * A local remodeling contractor's whole organic presence rides on a small
 * number of high-value links (the chamber of commerce, local press, supplier
 * and partner sites, review platforms). Losing one is invisible day to day —
 * nothing breaks on the site — so without this the first sign is a ranking
 * drop weeks later with no obvious cause. Broken backlinks (a link that used
 * to point at a live page but now 404s because a page was moved or removed)
 * are worse: they are link equity actively going to waste, fixable in
 * minutes with a redirect once someone knows the target exists.
 *
 * Endpoints (DataForSEO Backlinks API, Live — $0.024 per request + $0.06 per
 * 1,000 rows returned, https://dataforseo.com/pricing/backlinks/backlinks):
 *   - backlinks/summary/live      our domain + top competitors, one call each
 *   - backlinks/referring_domains/live   our domain only, up to max_referring_domains
 *   - backlinks/backlinks/live    our domain only, filtered to is_broken=true
 *   - backlinks/anchors/live      our domain only, anchor text mix
 *   - backlinks/history/live      our domain only, last 12 months (folded into the domain payload)
 *
 * A run costs roughly $0.035 (referring_domains) + $0.028 (broken backlinks)
 * + $0.026 (anchors) + $0.024 (history) + $0.024 per domain summary — about
 * $0.26 with the default 5 competitors. See estimateCost().
 */
class BacklinksSource extends IntelSource
{
    private const PER_REQUEST = 0.024;

    private const PER_ROW = 0.000036; // base $0.024 + $0.000036/row = $0.06 for a full 1,000-row call

    public function family(): string
    {
        return 'backlinks';
    }

    public function label(): string
    {
        return 'Backlinks';
    }

    public function estimateCost(): float
    {
        $domains = 1 + (int) $this->config('competitors', 5);
        $refLimit = (int) $this->config('max_referring_domains', 300);
        $cost = $domains * self::PER_REQUEST;
        $cost += self::PER_REQUEST + $refLimit * self::PER_ROW;
        $cost += self::PER_REQUEST + 100 * self::PER_ROW; // broken-backlink scan, capped at 100
        $cost += self::PER_REQUEST + 50 * self::PER_ROW;  // anchors
        $cost += self::PER_REQUEST + 12 * self::PER_ROW;  // 12 months of history

        return round($cost, 4);
    }

    public function collect(): array
    {
        $spentAtStart = $this->dfs->spent();
        $maxCost = (float) $this->config('max_cost', 0.4);
        $exceeded = fn () => ($this->dfs->spent() - $spentAtStart) > $maxCost;

        $our = $this->ourDomain();
        $snapshots = [];

        $summary = $exceeded() ? null : $this->fetchSummary($our);
        $payload = ['is_us' => true];
        $metrics = $this->summaryMetrics($summary);

        if (! $exceeded()) {
            foreach ($this->fetchReferringDomains($our) as $item) {
                $domain = mb_strtolower((string) ($item['domain'] ?? ''));
                if ($domain === '') {
                    continue;
                }
                $snapshots[] = new Snapshot('referring_domain', $domain, [
                    'rank' => isset($item['rank']) ? (int) $item['rank'] : null,
                    'backlinks' => (int) ($item['backlinks'] ?? 0),
                    'spam_score' => isset($item['backlinks_spam_score']) ? (int) $item['backlinks_spam_score'] : null,
                ], [
                    'first_seen' => $item['first_seen'] ?? null,
                    'lost_date' => $item['lost_date'] ?? null,
                ]);
            }
        }

        if (! $exceeded()) {
            foreach ($this->fetchBrokenTargets($our) as $subject => $row) {
                $snapshots[] = new Snapshot('broken_target', $subject, [
                    'links' => $row['links'],
                    'status' => $row['status'],
                ], [
                    'sources' => $row['sources'],
                    'status_code' => $row['status'],
                ]);
            }
        }

        if (! $exceeded()) {
            $anchors = $this->fetchAnchors($our);
            $payload['anchors'] = $this->anchorMix($anchors);
        }

        if (! $exceeded()) {
            $payload['history'] = $this->fetchHistory($our);
        }

        // Our own domain snapshot goes in last so it carries everything
        // gathered above (anchor mix, history trend) in one payload.
        if ($summary !== null || $metrics !== []) {
            array_unshift($snapshots, new Snapshot('domain', $our, $metrics, $payload));
        }

        $competitors = $this->competitorDomains((int) $this->config('competitors', 5));
        foreach ($competitors as $comp) {
            if ($exceeded()) {
                break;
            }
            $cSummary = $this->fetchSummary($comp);
            if ($cSummary === null) {
                continue;
            }
            $snapshots[] = new Snapshot('domain', $comp, $this->summaryMetrics($cSummary), ['is_us' => false]);
        }

        if ($snapshots === [] && $this->dfs->getLastError()) {
            throw new \RuntimeException('backlinks: ' . $this->dfs->getLastError());
        }

        return $snapshots;
    }

    public function findings(): array
    {
        $findings = [];
        $maxPerType = (int) $this->config('max_findings_per_type', 8);

        // Referring domains: diff today's set against the previous run's.
        $latestRef = $this->latestSet('referring_domain');
        $previousRef = $this->previousSet('referring_domain');
        if ($previousRef->isNotEmpty()) {
            $lost = $previousRef->keys()->diff($latestRef->keys())
                ->map(fn ($d) => [$d, (int) ($previousRef[$d]['metrics']['rank'] ?? 0)])
                ->sortByDesc(fn ($p) => $p[1])->take($maxPerType);
            foreach ($lost as [$domain, $rank]) {
                $findings[] = $this->finding(
                    'referring_domain_lost',
                    $rank >= 30 ? Finding::CRITICAL : Finding::WARN,
                    "Lost referring domain: {$domain}",
                    "{$domain} (rank {$rank}) linked to us as of the previous run and no longer appears among live referring domains.",
                    $domain,
                    null,
                    ['rank' => ['prev' => $rank, 'now' => null]],
                );
            }

            $new = $latestRef->keys()->diff($previousRef->keys())
                ->map(fn ($d) => [$d, (int) ($latestRef[$d]['metrics']['rank'] ?? 0)])
                ->sortByDesc(fn ($p) => $p[1])->take($maxPerType);
            foreach ($new as [$domain, $rank]) {
                $findings[] = $this->finding(
                    'referring_domain_new',
                    Finding::WIN,
                    "New referring domain: {$domain}",
                    "{$domain} (rank {$rank}) now links to us.",
                    $domain,
                    null,
                    ['rank' => ['prev' => null, 'now' => $rank]],
                );
            }
        }

        // Broken targets: whatever is broken right now, worst first.
        $broken = $this->latestSet('broken_target')
            ->sortByDesc(fn ($s) => $s['metrics']['links'] ?? 0)->take($maxPerType);
        foreach ($broken as $path => $s) {
            $links = (int) ($s['metrics']['links'] ?? 0);
            $sources = implode(', ', array_slice((array) ($s['payload']['sources'] ?? []), 0, 3));
            $findings[] = $this->finding(
                'broken_target',
                Finding::CRITICAL,
                "Links point at a dead page: {$path}",
                "{$links} backlink(s) point at {$path}, which returns HTTP {$s['metrics']['status']}. Redirect the URL to recover the link equity. Top sources: {$sources}.",
                $path,
                null,
                ['links' => ['prev' => null, 'now' => $links]],
            );
        }

        // Anchor mix: too many exact-match money anchors reads as manipulative to Google.
        $ourNow = $this->latest('domain', $this->ourDomain());
        $moneyPct = (float) ($ourNow['payload']['anchors']['money_pct'] ?? 0);
        $warnAt = (float) $this->config('money_anchor_pct_warn', 30);
        if ($ourNow && $moneyPct > $warnAt) {
            $ourPrev = $this->previous('domain', $this->ourDomain());
            $prevPct = $ourPrev['payload']['anchors']['money_pct'] ?? null;
            $findings[] = $this->finding(
                'money_anchor_ratio',
                Finding::INFO,
                'Exact-match anchor text is a large share of the link profile',
                sprintf('%.0f%% of anchor text weight is exact-match/commercial keywords (threshold %.0f%%) — a natural link profile is mostly brand and generic anchors.', $moneyPct, $warnAt),
                $this->ourDomain(),
                'anchor_ratio',
                ['money_anchor_pct' => ['prev' => $prevPct, 'now' => round($moneyPct, 1)]],
            );
        }

        // Competitor velocity: a competitor building links much faster than us.
        $latestDomains = $this->latestSet('domain');
        $previousDomains = $this->previousSet('domain');
        if ($previousDomains->isNotEmpty() && isset($latestDomains[$this->ourDomain()], $previousDomains[$this->ourDomain()])) {
            $ourGrowth = (int) ($latestDomains[$this->ourDomain()]['metrics']['referring_domains'] ?? 0) - (int) ($previousDomains[$this->ourDomain()]['metrics']['referring_domains'] ?? 0);
            foreach ($latestDomains as $domain => $now) {
                if ($domain === $this->ourDomain() || ! isset($previousDomains[$domain])) {
                    continue;
                }
                $growth = (int) ($now['metrics']['referring_domains'] ?? 0) - (int) ($previousDomains[$domain]['metrics']['referring_domains'] ?? 0);
                if ($growth > $ourGrowth + 10) {
                    $findings[] = $this->finding(
                        'competitor_velocity',
                        Finding::INFO,
                        "{$domain} is building links faster than us",
                        "{$domain} gained {$growth} referring domains since the last run vs our {$ourGrowth}.",
                        $domain,
                        'referring_domains_growth',
                        ['referring_domains' => ['prev' => (int) ($previousDomains[$domain]['metrics']['referring_domains'] ?? 0), 'now' => (int) ($now['metrics']['referring_domains'] ?? 0)]],
                    );
                }
            }
        }

        return $findings;
    }

    public function report(): array
    {
        $our = $this->ourDomain();
        $now = $this->latest('domain', $our);
        $prev = $this->previous('domain', $our);
        if (! $now) {
            return ['tiles' => [], 'tables' => [], 'note' => 'No backlink data collected yet.'];
        }

        $latestRef = $this->latestSet('referring_domain');
        $previousRef = $this->previousSet('referring_domain');
        $newCount = $previousRef->isNotEmpty() ? $latestRef->keys()->diff($previousRef->keys())->count() : 0;
        $lostSet = $previousRef->isNotEmpty() ? $previousRef->keys()->diff($latestRef->keys()) : collect();
        $broken = $this->latestSet('broken_target')->sortByDesc(fn ($s) => $s['metrics']['links'] ?? 0);

        $tile = fn ($label, $value, $prevValue = null, $unit = '') => array_filter([
            'label' => $label, 'value' => $value, 'prev' => $prevValue, 'unit' => $unit,
        ], fn ($v) => $v !== null);

        return [
            'tiles' => [
                $tile('Referring domains', $now['metrics']['referring_domains'] ?? null, $prev['metrics']['referring_domains'] ?? null),
                $tile('Backlinks', $now['metrics']['backlinks'] ?? null, $prev['metrics']['backlinks'] ?? null),
                $tile('Domain rank', $now['metrics']['rank'] ?? null, $prev['metrics']['rank'] ?? null),
                $tile('Broken targets', $broken->count()),
                $tile('New referring domains', $newCount),
                $tile('Lost referring domains', $lostSet->count()),
            ],
            'tables' => [
                [
                    'title' => 'Lost referring domains',
                    'columns' => ['Domain', 'Rank'],
                    'rows' => $lostSet->map(fn ($d) => [$d, (int) ($previousRef[$d]['metrics']['rank'] ?? 0)])
                        ->sortByDesc(fn ($r) => $r[1])->take(12)->values()->all(),
                ],
                [
                    'title' => 'Broken targets',
                    'columns' => ['Path', 'Links'],
                    'rows' => $broken->take(12)->map(fn ($s, $path) => [$path, (int) ($s['metrics']['links'] ?? 0)])->values()->all(),
                ],
            ],
            'note' => sprintf('Backlink profile for %s as of %s, tracked against %d competitor domain(s).', $our, $now['taken_on'], count($this->competitorDomains((int) $this->config('competitors', 5)))),
        ];
    }

    /** backlinks/summary/live for one domain, or null on failure. */
    protected function fetchSummary(string $domain): ?array
    {
        $envelope = $this->dfs->request('POST', '/backlinks/summary/live', [[
            'target' => $domain,
            'internal_list_limit' => 5,
            'backlinks_status_type' => 'live',
        ]]);

        return DataForSeoService::resultOf($envelope)[0] ?? null;
    }

    protected function summaryMetrics(?array $s): array
    {
        if ($s === null) {
            return [];
        }

        return [
            'backlinks' => (int) ($s['backlinks'] ?? 0),
            'referring_domains' => (int) ($s['referring_domains'] ?? 0),
            'referring_main_domains' => (int) ($s['referring_main_domains'] ?? 0),
            'rank' => isset($s['rank']) ? (int) $s['rank'] : null,
            'broken_backlinks' => (int) ($s['broken_backlinks'] ?? 0),
            'broken_pages' => (int) ($s['broken_pages'] ?? 0),
            'referring_domains_nofollow' => (int) ($s['referring_domains_nofollow'] ?? 0),
            'referring_ips' => (int) ($s['referring_ips'] ?? 0),
        ];
    }

    /** backlinks/referring_domains/live for our domain, strongest first. */
    protected function fetchReferringDomains(string $domain): array
    {
        $limit = (int) $this->config('max_referring_domains', 300);
        $envelope = $this->dfs->request('POST', '/backlinks/referring_domains/live', [[
            'target' => $domain,
            'limit' => $limit,
            'order_by' => ['rank,desc'],
            'backlinks_status_type' => 'live',
            'exclude_internal_backlinks' => true,
        ]]);

        return (array) (DataForSeoService::resultOf($envelope)[0]['items'] ?? []);
    }

    /**
     * backlinks/backlinks/live filtered to links whose target on our own
     * site is broken, grouped by target path.
     *
     * @return array<string, array{links: int, status: int, sources: list<string>}>
     */
    protected function fetchBrokenTargets(string $domain): array
    {
        $envelope = $this->dfs->request('POST', '/backlinks/backlinks/live', [[
            'target' => $domain,
            'limit' => 100,
            'filters' => ['is_broken', '=', true],
        ]]);
        $items = (array) (DataForSeoService::resultOf($envelope)[0]['items'] ?? []);

        $grouped = [];
        foreach ($items as $it) {
            $path = (string) parse_url((string) ($it['url_to'] ?? ''), PHP_URL_PATH) ?: '/';
            $grouped[$path] ??= ['links' => 0, 'status' => (int) ($it['url_to_status_code'] ?? 0), 'sources' => []];
            $grouped[$path]['links']++;
            $from = (string) ($it['domain_from'] ?? '');
            if ($from !== '' && count($grouped[$path]['sources']) < 5 && ! in_array($from, $grouped[$path]['sources'], true)) {
                $grouped[$path]['sources'][] = $from;
            }
        }

        return $grouped;
    }

    /** backlinks/anchors/live for our domain. */
    protected function fetchAnchors(string $domain): array
    {
        $envelope = $this->dfs->request('POST', '/backlinks/anchors/live', [[
            'target' => $domain,
            'limit' => 50,
            'order_by' => ['backlinks,desc'],
        ]]);

        return (array) (DataForSeoService::resultOf($envelope)[0]['items'] ?? []);
    }

    /** Brand vs generic vs money-keyword anchor mix, weighted by backlink count. */
    protected function anchorMix(array $items): array
    {
        $brandToken = mb_strtolower((string) preg_replace('/\s*&.*$/', '', (string) config('brand.name')) ?: 'gs construction');
        $moneyPattern = '/\b(remodel(?:ing|er)?|renovat(?:ion|e)|kitchen|bathroom|basement|addition|contractor|remodeler)\b/i';
        $generic = ['click here', 'here', 'website', 'home', 'homepage', 'this page', 'read more', 'learn more', 'visit site', 'source', 'link', ''];

        $brand = $money = $genericW = $total = 0;
        foreach ($items as $it) {
            $anchor = mb_strtolower(trim((string) ($it['anchor'] ?? '')));
            $weight = max(1, (int) ($it['backlinks'] ?? 0));
            $total += $weight;
            if (in_array($anchor, $generic, true) || str_contains($anchor, mb_strtolower((string) $this->ourDomain()))) {
                $genericW += $weight;
            } elseif ($brandToken !== '' && str_contains($anchor, $brandToken)) {
                $brand += $weight;
            } elseif (preg_match($moneyPattern, $anchor)) {
                $money += $weight;
            } else {
                $genericW += $weight;
            }
        }

        return [
            'sample' => count($items),
            'brand_pct' => $total ? round(100 * $brand / $total, 1) : 0.0,
            'money_pct' => $total ? round(100 * $money / $total, 1) : 0.0,
            'generic_pct' => $total ? round(100 * $genericW / $total, 1) : 0.0,
        ];
    }

    /** backlinks/history/live, last 12 months, for the domain payload's trend tile. */
    protected function fetchHistory(string $domain): array
    {
        $envelope = $this->dfs->request('POST', '/backlinks/history/live', [[
            'target' => $domain,
            'date_from' => now()->subMonths(12)->startOfMonth()->format('Y-m-d'),
        ]]);
        $items = (array) (DataForSeoService::resultOf($envelope)[0]['items'] ?? []);

        return array_map(fn ($it) => [
            'date' => mb_substr((string) ($it['date'] ?? ''), 0, 10),
            'backlinks' => (int) ($it['backlinks'] ?? 0),
            'referring_domains' => (int) ($it['referring_domains'] ?? 0),
            'rank' => isset($it['rank']) ? (int) $it['rank'] : null,
        ], $items);
    }
}
