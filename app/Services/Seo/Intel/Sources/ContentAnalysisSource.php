<?php

namespace App\Services\Seo\Intel\Sources;

use App\Services\DataForSeoService;
use App\Services\Seo\Intel\Finding;
use App\Services\Seo\Intel\IntelSource;
use App\Services\Seo\Intel\Snapshot;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Content Analysis: brand mentions across the open web — citations, press,
 * forums, directories, review round-ups — for us and the map-pack
 * competitors. A local remodeling contractor lives on word of mouth and
 * "who's trustworthy" signals; this surfaces the unlinked mentions worth
 * turning into a backlink, sentiment worth watching before it becomes a
 * review problem, and which topics are getting talked about right now (a
 * GEO signal — the same web text answer engines read to describe us).
 *
 * Endpoints (DataForSEO Content Analysis API, verified against
 * https://docs.dataforseo.com/v3/content_analysis/... 2026-09):
 *   - summary/live   ~$0.02/call — one call per brand + competitor term.
 *   - search/live    ~$0.02/call — one call per OUR term only (competitor
 *     mention detail isn't worth the spend; the summary already compares
 *     volume).
 *   - phrase_trends/live ~$0.02/call — one call per tracked service phrase,
 *     12 trailing months, monthly.
 *
 * Default run: 2 brand terms + 5 competitors (summary) + 2 brand terms
 * (search) + 2 phrases (trends) = 11 calls ≈ $0.22, under max_cost (0.3).
 */
class ContentAnalysisSource extends IntelSource
{
    public const DIRECTORY_DOMAINS = ['porch.com', 'homestars.com', 'houzz.com', 'yelp.com', 'angi.com', 'homeadvisor.com', 'bbb.org', 'buildzoom.com', 'thumbtack.com', 'nextdoor.com', 'facebook.com', 'yellowpages.com', 'mapquest.com', 'manta.com', 'bark.com'];

    public function family(): string
    {
        return 'content_analysis';
    }

    public function label(): string
    {
        return 'Content Analysis';
    }

    public function estimateCost(): float
    {
        $brand = count($this->brandTerms());
        $competitors = (int) $this->config('competitors', 5);
        $phrases = count($this->trendPhrases());
        $calls = $brand /* summary */ + $competitors /* summary */ + $brand /* search */ + $phrases /* trends */;

        return round($calls * 0.02, 3);
    }

    public function collect(): array
    {
        $spentAtStart = $this->dfs->spent();
        $maxCost = (float) $this->config('max_cost', 0.3);
        $overBudget = fn (): bool => ($this->dfs->spent() - $spentAtStart) > $maxCost;

        $brandTerms = $this->brandTerms();
        $competitorTerms = $this->competitorTerms((int) $this->config('competitors', 5));
        $snapshots = [];

        // 1. Mention volume + sentiment for us and the competitors.
        foreach (array_merge($brandTerms, $competitorTerms) as $term) {
            if ($overBudget()) {
                break;
            }
            $snap = $this->fetchSummary($term, in_array($term, $brandTerms, true) ? $this->ourDomain() : null);
            if ($snap !== null) {
                $snapshots[] = $snap;
            }
        }

        // 2. Individual mentions, OUR terms only — this is what gets acted on.
        foreach ($brandTerms as $term) {
            if ($overBudget()) {
                break;
            }
            foreach ($this->fetchSearch($term) as $snap) {
                $snapshots[] = $snap;
            }
        }

        // 3. Topic heat for content timing.
        foreach ($this->trendPhrases() as $phrase) {
            if ($overBudget()) {
                break;
            }
            $snap = $this->fetchPhraseTrend($phrase);
            if ($snap !== null) {
                $snapshots[] = $snap;
            }
        }

        if ($snapshots === [] && $this->dfs->getLastError()) {
            throw new \RuntimeException('Content Analysis: ' . $this->dfs->getLastError());
        }

        return $snapshots;
    }

    public function findings(): array
    {
        $out = [];
        $brandTerms = $this->brandTerms();
        $competitorTerms = $this->competitorTerms((int) $this->config('competitors', 5));
        $nowBrand = $this->latestSet('brand');
        $prevBrand = $this->previousSet('brand');

        $sum = fn (Collection $set, array $terms, string $metric): int => array_sum(array_map(
            fn ($t) => (int) ($set->get($t)['metrics'][$metric] ?? 0), $terms
        ));

        $havePrev = $brandTerms !== [] && $prevBrand->isNotEmpty();
        if ($havePrev) {
            $ourNow = $sum($nowBrand, $brandTerms, 'total_count');
            $ourPrev = $sum($prevBrand, $brandTerms, 'total_count');
            $ourNowNeg = $sum($nowBrand, $brandTerms, 'negative');
            $ourPrevNeg = $sum($prevBrand, $brandTerms, 'negative');
            $delta = $ourNow - $ourPrev;

            if ($delta >= 3) {
                $out[] = $this->finding('brand_mentions_up', Finding::WIN, 'Brand mentions climbing',
                    "Mentions across the web went from {$ourPrev} to {$ourNow}.", 'us', null,
                    ['total_count' => ['prev' => $ourPrev, 'now' => $ourNow]]);
            }
            if ($ourNowNeg > $ourPrevNeg) {
                $out[] = $this->finding('negative_mentions_up', Finding::WARN, 'Negative-sentiment mentions increased',
                    "Negative connotations went from {$ourPrevNeg} to {$ourNowNeg}.", 'us', null,
                    ['negative' => ['prev' => $ourPrevNeg, 'now' => $ourNowNeg]]);
            }
            foreach ($competitorTerms as $term) {
                $n = $nowBrand->get($term);
                $p = $prevBrand->get($term);
                if (! $n || ! $p) {
                    continue;
                }
                $compNow = (int) ($n['metrics']['total_count'] ?? 0);
                $compPrev = (int) ($p['metrics']['total_count'] ?? 0);
                $compDelta = $compNow - $compPrev;
                if ($compDelta - $delta >= 10) {
                    $out[] = $this->finding('competitor_mentions_outpacing', Finding::INFO,
                        "{$term} gaining more web mentions than us",
                        "{$term} +{$compDelta} vs our +{$delta} mentions this period.", $term, null,
                        ['total_count' => ['prev' => $compPrev, 'now' => $compNow]]);
                }
            }
        }

        // Mention-level findings: new mentions, unlinked mentions, negative mentions.
        $nowMentions = $this->latestSet('mention');
        $prevMentions = $this->previousSet('mention');
        $mentionFindings = [];
        foreach ($nowMentions as $url => $data) {
            $payload = $data['payload'];
            $metrics = $data['metrics'];
            $isNew = ! $prevMentions->has($url);
            $label = trim(($payload['domain'] ?? '') . ($payload['title'] ? ': ' . $payload['title'] : ''));
            $linkToUs = $payload['link_to_us'] ?? null;

            if ($isNew && $linkToUs === false) {
                $mentionFindings[] = $this->finding('unlinked_mention', Finding::INFO, 'Unlinked mention — ask for a link',
                    $label !== '' ? $label : $url, $url);
            } elseif ($isNew) {
                $mentionFindings[] = $this->finding('new_mention', Finding::WIN, 'New brand mention',
                    $label !== '' ? $label : $url, $url);
            }

            // search/live's connotation_types are 0-1 fractions (see fetchSearch()),
            // not counts — flag a citation as negative when the negative
            // probability clears the docs' own default connotation threshold
            // (positive_connotation_threshold / sentiments_connotation_threshold
            // = 0.4) and outweighs the positive fraction.
            $neg = (float) ($metrics['negative'] ?? 0);
            $pos = (float) ($metrics['positive'] ?? 0);
            // Directory listings (porch.com "unscreened … services in Zion")
            // carry our name but their sentiment is about the page template.
            if ($neg >= 0.4 && $neg > $pos && ! $this->isDirectoryDomain((string) (parse_url($url, PHP_URL_HOST) ?: ''))) {
                $mentionFindings[] = $this->finding('negative_mention', Finding::WARN, 'Negative-sentiment mention',
                    $label !== '' ? $label : $url, $url);
            }
        }
        $maxFindings = (int) $this->config('max_findings', 20);
        $out = array_merge($out, array_slice($mentionFindings, 0, max(0, $maxFindings)));

        // Topic heat.
        foreach ($this->trendPhrases() as $phrase) {
            $p = $this->latest('phrase', $phrase);
            if (! $p) {
                continue;
            }
            $avg = (float) ($p['metrics']['avg_12mo'] ?? 0);
            $last = (float) ($p['metrics']['last_month'] ?? 0);
            if ($avg > 0 && $last >= $avg * 1.25) {
                $out[] = $this->finding('phrase_trending', Finding::INFO, "\"{$phrase}\" search interest is up",
                    sprintf('Last month had %d mentions vs a 12-month average of %.1f.', (int) $last, $avg), $phrase, null,
                    ['monthly_mentions' => ['prev' => round($avg, 1), 'now' => $last]]);
            }
        }

        return $out;
    }

    public function report(): array
    {
        $brandTerms = $this->brandTerms();
        $competitorTerms = $this->competitorTerms((int) $this->config('competitors', 5));
        $nowBrand = $this->latestSet('brand');
        $prevBrand = $this->previousSet('brand');
        $nowMentions = $this->latestSet('mention');

        $sum = fn (Collection $set, array $terms, string $metric): int => array_sum(array_map(
            fn ($t) => (int) ($set->get($t)['metrics'][$metric] ?? 0), $terms
        ));

        $ourNow = $sum($nowBrand, $brandTerms, 'total_count');
        $ourPrev = $prevBrand->isNotEmpty() ? $sum($prevBrand, $brandTerms, 'total_count') : null;
        $posNow = $sum($nowBrand, $brandTerms, 'positive');
        $negNow = $sum($nowBrand, $brandTerms, 'negative');
        $compNow = $sum($nowBrand, $competitorTerms, 'total_count');
        $unlinked = $nowMentions->filter(fn ($m) => ($m['payload']['link_to_us'] ?? null) === false)->count();

        $tiles = [
            ['label' => 'Our mentions', 'value' => $ourNow, 'prev' => $ourPrev, 'good' => 'up'],
            ['label' => 'Positive mentions', 'value' => $posNow, 'good' => 'up'],
            ['label' => 'Negative mentions', 'value' => $negNow, 'good' => 'down'],
            ['label' => 'Competitor mentions', 'value' => $compNow],
            ['label' => 'Unlinked mentions', 'value' => $unlinked],
        ];

        $mentionRows = $nowMentions
            ->sortByDesc(fn ($m) => (string) ($m['payload']['date'] ?? ''))
            ->take(12)
            ->map(function ($m) {
                // Mention-level connotations are 0-1 fractions (search/live); see
                // fetchSearch() and the negative_mention threshold note above.
                $neg = (float) ($m['metrics']['negative'] ?? 0);
                $pos = (float) ($m['metrics']['positive'] ?? 0);
                $sentiment = $neg >= 0.4 && $neg > $pos ? 'negative' : ($pos > $neg ? 'positive' : 'neutral');
                $linked = $m['payload']['link_to_us'] ?? null;

                return [(string) ($m['payload']['domain'] ?? ''), (string) ($m['payload']['title'] ?? ''), $sentiment, $linked === null ? '—' : ($linked ? 'yes' : 'no')];
            })->values()->all();

        $competitorRows = collect($competitorTerms)
            ->map(fn ($t) => [$t, (int) ($nowBrand->get($t)['metrics']['total_count'] ?? 0)])
            ->sortByDesc(fn ($r) => $r[1])->take(12)->values()->all();

        $day = $nowBrand->first()['taken_on'] ?? $nowMentions->first()['taken_on'] ?? null;
        $note = $day
            ? "Brand and competitor mentions across the web via DataForSEO Content Analysis, last measured {$day}."
            : 'No Content Analysis data collected yet.';

        return [
            'tiles' => $tiles,
            'tables' => [
                ['title' => 'Recent mentions', 'columns' => ['Domain', 'Title', 'Sentiment', 'Linked'], 'rows' => $mentionRows],
                ['title' => 'Competitor mentions', 'columns' => ['Competitor', 'Total mentions'], 'rows' => $competitorRows],
            ],
            'note' => $note,
        ];
    }

    /**
     * Our terms — the business name(s) to search the web for. Defaults to
     * just the tenant-scoped brand name (config/brand.php, overridden per
     * site under config/sites/{slug}/brand.php). This file is shared code
     * that runs for every tenant, so it must not default in a
     * GSC-specific literal here — a per-tenant "brand + service area"
     * variant belongs in seo-intel.families.content_analysis.brand_terms
     * for that site, not a code-level default shipped to every tenant.
     */
    protected function brandTerms(): array
    {
        $configured = array_filter(array_map(fn ($t) => trim((string) $t), (array) $this->config('brand_terms', [])));
        if ($configured !== []) {
            return array_values(array_unique($configured));
        }
        // Default: every configured spelling of the business name, and its
        // "& → and" form ("GS Construction and Remodeling" is how porch.com
        // writes it). A name under three words is too ambiguous to search on
        // its own — "GS Construction" alone matches a Korean conglomerate and
        // giant-slalom results — so short names are dropped unless brand_terms
        // is set explicitly for the site.
        $terms = [];
        foreach (array_unique(array_filter([(string) config('brand.name'), (string) config('seo.site_name'), (string) config('brand.legal_name')])) as $name) {
            $terms[] = $name;
            if (str_contains($name, '&')) {
                $terms[] = preg_replace('/\s*&\s*/', ' and ', $name);
            }
        }
        $terms = array_filter(array_map('trim', $terms), fn ($t) => str_word_count(preg_replace('/[^a-z ]/i', ' ', $t)) >= 3);

        return array_values(array_unique($terms));
    }

    /** Service phrases to watch for seasonal/topical heat. */
    protected function trendPhrases(): array
    {
        $phrases = (array) $this->config('trend_phrases', ['kitchen remodeling', 'bathroom remodeling']);

        return array_values(array_unique(array_filter(array_map(fn ($p) => trim((string) $p), $phrases))));
    }

    /** Top map-pack competitors by pack presence, for a comparison term each. */
    protected function competitorTerms(int $limit): array
    {
        if ($limit <= 0 || ! Schema::hasTable('map_pack_competitors')) {
            return [];
        }

        return Tenancy::table('map_pack_competitors')
            ->whereNotNull('name')->where('pack_points', '>', 0)
            ->select('name', DB::raw('SUM(pack_points) w'))
            ->groupBy('name')->orderByDesc('w')->limit($limit)
            ->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->unique()->values()->all();
    }

    protected function fetchSummary(string $term, ?string $excludeDomain = null): ?Snapshot
    {
        // Exact phrase (the docs' quoted form): a bare "GS Construction"
        // matched 184,000 ski-race and adventure pages ("GS" = giant slalom).
        // Our own site is excluded from our own mention count.
        $task = [
            'keyword' => self::phrase($term),
            // Skip ecommerce listings — noise for a brand-mention read.
            'page_type' => ['organization', 'news', 'blogs', 'message-boards'],
            'internal_list_limit' => 10,
        ];
        if ($excludeDomain !== null) {
            $task['initial_dataset_filters'] = ['main_domain', '<>', $excludeDomain];
        }
        $env = $this->dfs->request('POST', '/content_analysis/summary/live', [$task]);
        $item = DataForSeoService::resultOf($env)[0] ?? null;
        if (! is_array($item)) {
            return null;
        }
        $conn = (array) ($item['connotation_types'] ?? []);

        return new Snapshot('brand', $term, [
            'total_count' => (int) ($item['total_count'] ?? 0),
            'positive' => (int) ($conn['positive'] ?? 0),
            'negative' => (int) ($conn['negative'] ?? 0),
            'neutral' => (int) ($conn['neutral'] ?? 0),
        ], [
            'top_domains' => array_slice((array) ($item['top_domains'] ?? []), 0, 10),
            'countries' => (array) ($item['countries'] ?? []),
        ]);
    }

    /** @return Snapshot[] */
    protected function fetchSearch(string $term): array
    {
        $env = $this->dfs->request('POST', '/content_analysis/search/live', [[
            'keyword' => self::phrase($term),
            'filters' => ['main_domain', '<>', $this->ourDomain()],
            'search_mode' => 'as_is',
            'limit' => (int) $this->config('mention_limit', 50),
            'order_by' => ['content_info.date_published,desc'],
        ]]);
        $result = DataForSeoService::resultOf($env)[0] ?? null;
        $items = is_array($result) ? (array) ($result['items'] ?? []) : [];
        $out = [];
        foreach ($items as $it) {
            $url = (string) ($it['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $ci = (array) ($it['content_info'] ?? []);
            // Unlike summary/live (integer citation counts), search/live's
            // connotation_types are 0-1 probability fractions per citation —
            // verified against https://docs.dataforseo.com/v3/content_analysis/search/live/
            // (example: positive 0.123..., negative 0.412..., neutral 0.465...).
            // Keep them as floats; do not cast to int.
            $conn = (array) ($ci['connotation_types'] ?? []);
            $rating = $ci['rating']['rating_value'] ?? null;
            $out[] = new Snapshot('mention', $url, [
                'positive' => (float) ($conn['positive'] ?? 0),
                'negative' => (float) ($conn['negative'] ?? 0),
                'neutral' => (float) ($conn['neutral'] ?? 0),
                'rating' => is_numeric($rating) ? (float) $rating : null,
            ], [
                'domain' => (string) ($it['domain'] ?? ''),
                'title' => mb_substr((string) ($ci['title'] ?? ''), 0, 160),
                'date' => $ci['date_published'] ?? null,
                'term' => $term,
                // The search/live response documents no "links to target" field —
                // reported unlinked only when a future field exposes it; null
                // means "unknown", not "not linked".
                'link_to_us' => null,
            ]);
        }

        return $out;
    }

    protected function fetchPhraseTrend(string $phrase): ?Snapshot
    {
        $env = $this->dfs->request('POST', '/content_analysis/phrase_trends/live', [[
            'keyword' => $phrase,
            'date_from' => now()->subMonths(12)->startOfMonth()->toDateString(),
            'date_to' => now()->toDateString(),
            'date_group' => 'month',
        ]]);
        $rows = DataForSeoService::resultOf($env);
        if ($rows === []) {
            return null;
        }
        $series = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $series[] = ['date' => $r['date'] ?? null, 'total_count' => (int) ($r['total_count'] ?? 0)];
        }
        if ($series === []) {
            return null;
        }
        $counts = array_column($series, 'total_count');
        $avg = array_sum($counts) / count($counts);
        $last = (int) end($counts);

        return new Snapshot('phrase', $phrase, [
            'last_month' => $last,
            'avg_12mo' => round($avg, 2),
            'months' => count($series),
        ], ['series' => $series]);
    }

    /** The docs' exact-phrase form: "\"gs construction & remodeling\"". */
    public static function phrase(string $term): string
    {
        return '"' . trim($term, '"') . '"';
    }

    /** Aggregators and directories: citations worth counting, sentiment not worth reading. */
    protected function isDirectoryDomain(string $host): bool
    {
        $host = mb_strtolower($host);
        foreach ((array) $this->config('directory_domains', self::DIRECTORY_DOMAINS) as $d) {
            $d = mb_strtolower(trim((string) $d));
            if ($d !== '' && ($host === $d || str_ends_with($host, '.' . $d))) {
                return true;
            }
        }

        return false;
    }
}
