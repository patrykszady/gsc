<?php

namespace App\Services\Seo\Intel\Sources;

use App\Services\DataForSeoService;
use App\Services\Seo\Intel\Finding;
use App\Services\Seo\Intel\IntelSource;
use App\Services\Seo\Intel\Snapshot;

/**
 * Domain Analytics: what the competitors' sites are built with, and how
 * old/stable their domains are — cheap intelligence that explains map-pack
 * and organic strength (a live-chat widget, a review-collection plugin, a
 * schema/SEO plugin all correlate with a site someone actively maintains)
 * and flags a competitor domain that may lapse.
 *
 * Endpoints (both DataForSEO Domain Analytics API, "live" — synchronous,
 * one task per POST):
 *  - domain_analytics/technologies/domain_technologies/live — one call per
 *    domain (ours + up to config('competitors', 12) competitors), $0.012/task
 *    per the pricing page (the docs' own worked example bills $0.01/task —
 *    either way, a handful of cents for the whole list). Returns a
 *    `technologies` tree keyed by category/subcategory, e.g.
 *    content.cms => ["WordPress"], servers.cdn => ["Cloudflare"].
 *  - domain_analytics/whois/overview/live — competitor domains only, in
 *    batches (filters: ["domain","in",[...]], limit = batch size), returning
 *    result[0].items[]. Priced per the docs' published formula: $0.12/task +
 *    $0.0012/item returned (docs' own worked example: 1,000*0.12 +
 *    1,000,000*0.0012 = $1,320) — not a flat per-call price.
 *
 * Both together for the default 12 competitors run well inside a couple of
 * dimes — see estimateCost(). config('max_cost', 0.2) is the hard stop:
 * collect() checks spend before every chargeable call and returns whatever
 * it already has once the cap is crossed.
 */
class DomainAnalyticsSource extends IntelSource
{
    /** Curated because the API groups tech under generic buckets (web_development, add_ons, servers…), not by business capability. */
    private const LIVE_CHAT = ['Intercom', 'Drift', 'Tawk.to', 'LiveChat', 'Zendesk Chat', 'Crisp', 'Tidio', 'Olark', 'HubSpot Chat', 'Facebook Messenger', 'Chatra', 'LiveAgent', 'Smartsupp', 'Pure Chat'];

    private const REVIEW_WIDGET = ['Trustpilot', 'Yotpo', 'Judge.me', 'BirdEye', 'Podium', 'Shopper Approved', 'Reviews.io', 'Verified Reviews', 'Feefo', 'TrustSpot', 'Elfsight Reviews', 'Stamped.io', 'Google Reviews Widget', 'Trustindex'];

    private const SCHEMA_PLUGIN = ['Yoast SEO', 'Rank Math', 'All in One SEO', 'Schema Pro', 'WP Schema Pro', 'Schema & Structured Data for WP', 'SchemaApp', 'Structured Data for WP & AMP', 'The SEO Framework'];

    public function family(): string
    {
        return 'domain_analytics';
    }

    public function label(): string
    {
        return 'Domain analytics (tech & whois)';
    }

    public function estimateCost(): float
    {
        $n = 1 + (int) $this->config('competitors', 12);
        $chunkSize = max(1, (int) $this->config('whois_chunk', 6));
        $competitorCount = max(0, $n - 1);
        $whoisBatches = (int) ceil($competitorCount / $chunkSize);
        // Whois: $0.12/task + $0.0012/item returned. The last batch may hold
        // fewer than $chunkSize items, so cost by actual item count, not
        // batches * chunkSize.
        $whoisCost = $whoisBatches * 0.12 + $competitorCount * 0.0012;

        return round($n * 0.012 + $whoisCost, 3);
    }

    public function collect(): array
    {
        $spentAtStart = $this->dfs->spent();
        $maxCost = (float) $this->config('max_cost', 0.2);
        $budgetLeft = fn () => $maxCost - ($this->dfs->spent() - $spentAtStart);

        $ours = $this->ourDomain();
        $competitors = $this->competitorDomains((int) $this->config('competitors', 12));
        $domains = array_values(array_unique(array_merge([$ours], $competitors)));

        $snapshots = [];

        foreach ($domains as $domain) {
            if ($budgetLeft() <= 0) {
                break;
            }
            $env = $this->dfs->request('POST', '/domain_analytics/technologies/domain_technologies/live', [['target' => $domain]]);
            $item = DataForSeoService::resultOf($env)[0] ?? null;
            if (! is_array($item)) {
                continue;
            }
            $tree = (array) ($item['technologies'] ?? []);
            $flat = $this->flattenTechnologies($tree);
            $names = array_map(fn ($t) => $t['name'], $flat);

            $snapshots[] = new Snapshot('tech', $domain, [
                'count' => count($flat),
                'has_live_chat' => $this->matchesAny($names, self::LIVE_CHAT) ? 1 : 0,
                'has_review_widget' => $this->matchesAny($names, self::REVIEW_WIDGET) ? 1 : 0,
                'has_schema_plugin' => $this->matchesAny($names, self::SCHEMA_PLUGIN) ? 1 : 0,
                'uses_wordpress' => in_array('WordPress', $names, true) || ! empty($tree['add_ons']['wordpress_plugins']) ? 1 : 0,
                'uses_cloudflare' => in_array('Cloudflare', $names, true) ? 1 : 0,
            ], [
                'title' => (string) ($item['title'] ?? ''),
                'domain_rank' => $item['domain_rank'] ?? null,
                'technologies' => $flat,
            ]);
        }

        $chunkSize = max(1, (int) $this->config('whois_chunk', 6));
        foreach (array_chunk($competitors, $chunkSize) as $chunk) {
            if ($budgetLeft() <= 0) {
                break;
            }
            $env = $this->dfs->request('POST', '/domain_analytics/whois/overview/live', [[
                'filters' => [['domain', 'in', array_values($chunk)]],
                'limit' => count($chunk),
            ]]);
            $items = (array) (DataForSeoService::resultOf($env)[0]['items'] ?? []);
            foreach ($items as $it) {
                if (empty($it['domain'])) {
                    continue;
                }
                $created = $this->parseTs($it['created_datetime'] ?? null);
                $expires = $this->parseTs($it['expiration_datetime'] ?? null);
                $organic = (array) ($it['metrics']['organic'] ?? []);

                $snapshots[] = new Snapshot('whois', (string) $it['domain'], [
                    'domain_age_days' => $created !== null ? (int) round((now()->getTimestamp() - $created) / 86400) : null,
                    'days_until_expiration' => $expires !== null ? (int) round(($expires - now()->getTimestamp()) / 86400) : null,
                    'referring_domains' => (int) ($it['backlinks_info']['referring_domains'] ?? 0),
                    'backlinks' => (int) ($it['backlinks_info']['backlinks'] ?? 0),
                    'organic_count' => (int) ($organic['count'] ?? 0),
                    'organic_etv' => (float) ($organic['etv'] ?? 0),
                ], [
                    'registrar' => $it['registrar'] ?? null,
                    'created_datetime' => $it['created_datetime'] ?? null,
                    'expiration_datetime' => $it['expiration_datetime'] ?? null,
                    'registered' => $it['registered'] ?? null,
                ]);
            }
        }

        if ($snapshots === [] && $this->dfs->getLastError()) {
            throw new \RuntimeException('DomainAnalyticsSource: ' . $this->dfs->getLastError());
        }

        return $snapshots;
    }

    public function findings(): array
    {
        $ours = $this->ourDomain();
        $maxFindings = (int) $this->config('max_findings', 25);
        $threshold = (float) $this->config('capability_threshold', 0.6);
        $expiringDays = (int) $this->config('expiring_days', 90);

        $techNow = $this->latestSet('tech');
        $techPrev = $this->previousSet('tech');
        $whoisNow = $this->latestSet('whois');

        // Priority (higher = kept first when the cap truncates): domain_expiring
        // and capability_gap matter more than the purely informational
        // tech_added stream, which can be large and noisy on a big re-crawl.
        // Mirrors OnPageSource's candidate-priority pattern.
        $candidates = [];

        // A competitor adopted a technology since the previous run.
        foreach ($techNow as $domain => $now) {
            if ($domain === $ours || ! isset($techPrev[$domain])) {
                continue; // ourselves, or the first sighting of this domain (not a change)
            }
            $prevNames = array_column((array) ($techPrev[$domain]['payload']['technologies'] ?? []), 'name');
            $nowNames = array_column((array) ($now['payload']['technologies'] ?? []), 'name');
            foreach (array_diff($nowNames, $prevNames) as $tech) {
                $candidates[] = [10, $this->finding('tech_added', Finding::INFO, "{$domain} added {$tech}",
                    "Seen using {$tech} since the previous run.", $domain, $tech)];
            }
        }

        // Capabilities most competitors have that we lack.
        $competitorRows = $techNow->except($ours);
        $total = $competitorRows->count();
        if ($total > 0) {
            foreach (['has_live_chat' => 'live chat', 'has_review_widget' => 'a review-collection widget', 'has_schema_plugin' => 'a schema/SEO plugin'] as $metric => $label) {
                $have = $competitorRows->filter(fn ($r) => (int) ($r['metrics'][$metric] ?? 0) === 1)->count();
                $ratio = $have / $total;
                $weHave = (int) ($techNow[$ours]['metrics'][$metric] ?? 0) === 1;
                if ($ratio >= $threshold && ! $weHave) {
                    $pct = (int) round($ratio * 100);
                    $candidates[] = [50, $this->finding('capability_gap', Finding::INFO, "{$pct}% of competitors run {$label}, we don't",
                        "{$have} of {$total} competitor sites carry {$label}.", $ours, $metric,
                        [$metric => ['prev' => null, 'now' => $have]])];
                }
            }
        }

        // Competitor domain expiring soon.
        $expiring = [];
        foreach ($whoisNow as $domain => $row) {
            $days = $row['metrics']['days_until_expiration'] ?? null;
            if ($days !== null && $days <= $expiringDays) {
                $expiring[] = [$domain, $days, $row];
            }
        }
        usort($expiring, fn ($a, $b) => $a[1] <=> $b[1]);
        foreach ($expiring as [$domain, $days, $row]) {
            $candidates[] = [80, $this->finding('domain_expiring', Finding::INFO,
                $days < 0 ? "{$domain}'s domain registration has lapsed" : "{$domain}'s domain expires in {$days} days",
                'Registrar: ' . ($row['payload']['registrar'] ?? 'unknown') . '.', $domain, null,
                ['days_until_expiration' => ['prev' => null, 'now' => $days]])];
        }

        // Our own tech stack changed.
        if (isset($techNow[$ours], $techPrev[$ours])) {
            $prevNames = array_column((array) ($techPrev[$ours]['payload']['technologies'] ?? []), 'name');
            $nowNames = array_column((array) ($techNow[$ours]['payload']['technologies'] ?? []), 'name');
            $added = array_diff($nowNames, $prevNames);
            $removed = array_diff($prevNames, $nowNames);
            if ($added || $removed) {
                $detail = trim(($added ? 'Added: ' . implode(', ', $added) . '. ' : '') . ($removed ? 'Removed: ' . implode(', ', $removed) . '.' : ''));
                $candidates[] = [60, $this->finding('our_tech_changed', Finding::INFO, 'Our tech stack changed', $detail, $ours, null,
                    ['count' => ['prev' => count($prevNames), 'now' => count($nowNames)]])];
            }
        }

        usort($candidates, fn ($a, $b) => $b[0] <=> $a[0]);
        $findings = array_map(fn ($c) => $c[1], $candidates);

        return array_slice($findings, 0, max(1, $maxFindings));
    }

    public function report(): array
    {
        $techNow = $this->latestSet('tech');
        $whoisNow = $this->latestSet('whois');
        $ours = $this->ourDomain();
        $competitorRows = $techNow->except($ours);
        $total = $competitorRows->count();

        if ($total === 0 && $techNow->isEmpty()) {
            return ['tiles' => [], 'tables' => [], 'note' => 'No domain analytics collected yet.'];
        }

        $withChat = $competitorRows->filter(fn ($r) => (int) ($r['metrics']['has_live_chat'] ?? 0) === 1)->count();
        $withReviews = $competitorRows->filter(fn ($r) => (int) ($r['metrics']['has_review_widget'] ?? 0) === 1)->count();
        $oldest = $whoisNow->max(fn ($r) => $r['metrics']['domain_age_days'] ?? null);

        $rows = [];
        foreach ($competitorRows as $domain => $row) {
            $whois = $whoisNow[$domain] ?? null;
            $cms = collect((array) ($row['payload']['technologies'] ?? []))->first(fn ($t) => str_starts_with((string) $t['category'], 'content.cms'));
            $rows[] = [
                $domain,
                $cms['name'] ?? ((int) ($row['metrics']['uses_wordpress'] ?? 0) === 1 ? 'WordPress' : '—'),
                (int) ($row['metrics']['has_live_chat'] ?? 0) === 1 ? 'yes' : '—',
                (int) ($row['metrics']['has_review_widget'] ?? 0) === 1 ? 'yes' : '—',
                isset($whois['metrics']['domain_age_days']) && $whois['metrics']['domain_age_days'] !== null ? (int) round($whois['metrics']['domain_age_days'] / 365) . 'y' : '—',
            ];
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));

        $takenOn = $this->store->latestDay($this->family(), 'tech');

        return [
            'tiles' => [
                ['label' => 'Competitors profiled', 'value' => $total],
                ['label' => 'With live chat', 'value' => $withChat, 'unit' => "/{$total}"],
                ['label' => 'With review widget', 'value' => $withReviews, 'unit' => "/{$total}"],
                ['label' => 'Oldest competitor domain', 'value' => $oldest !== null ? (int) round($oldest / 365) : null, 'unit' => 'yr'],
            ],
            'tables' => [[
                'title' => 'Competitors',
                'columns' => ['Domain', 'CMS', 'Live chat', 'Reviews widget', 'Domain age'],
                'rows' => array_slice($rows, 0, 12),
            ]],
            'note' => $takenOn ? "Technology and registration data for {$ours} and {$total} competitors, measured {$takenOn}." : 'No domain analytics collected yet.',
        ];
    }

    /** @return list<array{category: string, name: string}> */
    private function flattenTechnologies(array $tree): array
    {
        $out = [];
        foreach ($tree as $category => $subcats) {
            foreach ((array) $subcats as $subcat => $names) {
                foreach ((array) $names as $name) {
                    $out[] = ['category' => "{$category}.{$subcat}", 'name' => (string) $name];
                }
            }
        }

        return $out;
    }

    private function matchesAny(array $names, array $known): bool
    {
        foreach ($names as $name) {
            foreach ($known as $k) {
                if (mb_strtolower((string) $name) === mb_strtolower($k)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function parseTs(mixed $value): ?int
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $ts = strtotime($value);

        return $ts !== false ? $ts : null;
    }
}
