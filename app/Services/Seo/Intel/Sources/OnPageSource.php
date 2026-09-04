<?php

namespace App\Services\Seo\Intel\Sources;

use App\Services\DataForSeoService;
use Illuminate\Support\Carbon;
use App\Services\Seo\Intel\Finding;
use App\Services\Seo\Intel\IntelSource;
use App\Services\Seo\Intel\Snapshot;

/**
 * On-Page API crawl of our own site (gs.construction) — the technical-SEO
 * audit page by page that Search Console and GA4 never give us: which pages
 * 404/500, which share a duplicate title or meta description, which are thin
 * or missing an H1, which sit behind a redirect chain. Google Search Console
 * reports what got indexed; this reports why a page might not.
 *
 * Flow: on_page/task_post starts a crawl (the only priced call — 'basic'
 * mode, no JS/resource rendering, ~$0.00015 per crawled page per DataForSEO's
 * On-Page pricing page, so 600 pages ≈ $0.09), then on_page/summary/{id} is
 * polled until crawl_progress is 'finished' (free). Once finished,
 * on_page/pages (worst onpage_score first), on_page/duplicate_tags (title +
 * description) and on_page/redirect_chains fill in the worst pages in
 * detail — all free retrieval calls billed only at task_post time.
 *
 * Snapshots: kind 'summary' (subject = our domain) holds the site-wide
 * checks map; kind 'page' (subject = the URL path) holds one row per issue
 * page with its failed checks and, for duplicate tags, the sibling URLs that
 * share the tag.
 */
class OnPageSource extends IntelSource
{
    /** Documented On-Page API price per crawled page, basic mode. */
    private const PRICE_PER_PAGE = 0.00015;

    public function family(): string
    {
        return 'onpage';
    }

    public function label(): string
    {
        return 'On-Page crawl';
    }

    public function estimateCost(): float
    {
        return round(((int) $this->config('max_pages', 600)) * self::PRICE_PER_PAGE, 4);
    }

    public function collect(): array
    {
        $spentAtStart = $this->dfs->spent();
        $budget = (float) $this->config('max_cost', 0.20);
        $maxPages = (int) $this->config('max_pages', 600);
        if ($maxPages * self::PRICE_PER_PAGE > $budget) {
            $maxPages = max(10, (int) floor($budget / self::PRICE_PER_PAGE));
        }

        // A crawl can wait in DataForSEO's queue for hours, so the flow is
        // post now, collect on whichever later run finds it finished. The
        // pending task id lives in a 'crawl_task' snapshot; a new crawl is
        // posted only when none is pending and the last one is old enough.
        $pending = $this->latest('crawl_task', 'pending');
        $id = ($pending && empty($pending['payload']['finished'])) ? (string) ($pending['payload']['id'] ?? '') : '';
        $posted = false;
        if ($id === '') {
            $last = $this->latest('summary', $this->ourDomain());
            $minDays = (int) $this->config('min_interval_days', 6);
            if ($last && Carbon::parse($last['taken_on'])->gt(now()->subDays($minDays))) {
                return $this->skip(); // crawled recently — nothing to do until the next slot
            }
            $id = (string) $this->dfs->postTask('/on_page/task_post', [
                'target' => $this->ourDomain(),
                'max_crawl_pages' => $maxPages,
                'load_resources' => false,
                'enable_javascript' => false,
                'enable_browser_rendering' => false,
            ]);
            if ($id === '') {
                return [];
            }
            $posted = true;
        }
        $taskSnapshot = fn (bool $finished) => new Snapshot('crawl_task', 'pending', ['finished' => $finished ? 1 : 0], ['id' => $id, 'posted_on' => $posted ? now()->toDateString() : ($pending['payload']['posted_on'] ?? now()->toDateString()), 'finished' => $finished]);

        $maxWait = (int) $this->config('max_wait', 600);
        $interval = (int) $this->config('poll_interval', 30);
        $summary = $this->dfs->pollUntil(function () use ($id) {
            $data = $this->dfs->request('GET', "/on_page/summary/{$id}");
            $result = DataForSeoService::resultOf($data)[0] ?? null;

            return is_array($result) && ($result['crawl_progress'] ?? null) === 'finished' ? $result : null;
        }, $maxWait, $interval);

        if (! is_array($summary)) {
            // Still queued or crawling: remember the task (only when just
            // posted — a pickup run that finds it still pending is a no-op).
            return $posted ? [$taskSnapshot(false)] : $this->skip();
        }

        $pm = (array) ($summary['page_metrics'] ?? []);
        $checks = (array) ($pm['checks'] ?? []);
        $crawlStatus = (array) ($summary['crawl_status'] ?? []);

        // Follow-up retrieval calls are free (billed only at task_post), but
        // still respect the cost cap in spirit — stop reaching for detail if
        // the run has already spent past its budget.
        $overBudget = fn () => ($this->dfs->spent() - $spentAtStart) > $budget;

        $maxIssuePages = (int) $this->config('max_issue_pages', 300);
        $pagesItems = [];
        $pagesTotal = 0;
        if (! $overBudget()) {
            $pagesData = $this->dfs->request('POST', '/on_page/pages', [[
                'id' => $id,
                'limit' => min(1000, max(1, $maxIssuePages)),
                'order_by' => ['onpage_score,asc'],
                'filters' => [['onpage_score', '<', 100]],
            ]]);
            $pagesResult = DataForSeoService::resultOf($pagesData)[0] ?? [];
            $pagesItems = (array) ($pagesResult['items'] ?? []);
            $pagesTotal = (int) ($pagesResult['total_items_count'] ?? count($pagesItems));
        }

        $maxDupGroups = (int) $this->config('max_dup_groups', 50);
        $dupTitleGroups = $overBudget() ? [] : $this->duplicateGroups($id, 'duplicate_title', $maxDupGroups);
        $dupDescGroups = $overBudget() ? [] : $this->duplicateGroups($id, 'duplicate_description', $maxDupGroups);

        $maxRedirects = (int) $this->config('max_redirect_chains', 50);
        $redirectItems = [];
        if (! $overBudget()) {
            $redirectData = $this->dfs->request('POST', '/on_page/redirect_chains', [['id' => $id, 'limit' => $maxRedirects]]);
            $redirectItems = (array) (DataForSeoService::resultOf($redirectData)[0]['items'] ?? []);
        }

        // path => [other urls sharing the tag]
        $dupTitleSiblings = $this->siblingsByPath($dupTitleGroups);
        $dupDescSiblings = $this->siblingsByPath($dupDescGroups);
        // path => chain hops
        $redirectByPath = [];
        foreach ($redirectItems as $chain) {
            $hops = (array) ($chain['chain'] ?? []);
            $from = (string) ($hops[0]['page_from'] ?? $hops[0]['link_from'] ?? '');
            if ($from === '') {
                continue;
            }
            $redirectByPath[$this->pathOf($from)] = [
                'is_loop' => (bool) ($chain['is_redirect_loop'] ?? false),
                'hops' => array_map(fn ($h) => ['from' => $h['page_from'] ?? $h['link_from'] ?? null, 'to' => $h['page_to'] ?? $h['link_to'] ?? null], $hops),
            ];
        }

        $snapshots = [
            $taskSnapshot(true),
            new Snapshot('summary', $this->ourDomain(), [
                'onpage_score' => round((float) ($pm['onpage_score'] ?? 0), 2),
                'pages_crawled' => (int) ($crawlStatus['pages_crawled'] ?? 0),
                'pages_in_queue' => (int) ($crawlStatus['pages_in_queue'] ?? 0),
                'pages_with_issues' => $pagesTotal,
                'duplicate_title' => (int) ($pm['duplicate_title'] ?? 0),
                'duplicate_description' => (int) ($pm['duplicate_description'] ?? 0),
                'duplicate_content' => (int) ($pm['duplicate_content'] ?? 0),
                'broken_links' => (int) ($pm['broken_links'] ?? 0),
                'broken_resources' => (int) ($pm['broken_resources'] ?? 0),
                'non_indexable' => (int) ($pm['non_indexable'] ?? 0),
                'redirect_loop' => (int) ($pm['redirect_loop'] ?? 0),
                'no_title' => (int) ($checks['no_title'] ?? 0),
                'no_description' => (int) ($checks['no_description'] ?? 0),
                'no_h1' => (int) ($checks['no_h1_tag'] ?? 0),
                'is_4xx' => (int) ($checks['is_4xx_code'] ?? 0),
                'is_5xx' => (int) ($checks['is_5xx_code'] ?? 0),
                'thin_content' => (int) ($checks['low_content_rate'] ?? 0),
                'high_loading_time' => (int) ($checks['high_loading_time'] ?? 0),
                'large_page_size' => (int) ($checks['large_page_size'] ?? 0),
                'no_image_alt' => (int) ($checks['no_image_alt'] ?? 0),
                'redirect_chains' => count($redirectItems),
            ], [
                'task_id' => $id,
                'crawl_progress' => $summary['crawl_progress'] ?? null,
                'checks' => $checks,
            ]),
        ];

        $seenPaths = [];
        foreach ($pagesItems as $item) {
            $snapshots[] = $this->pageSnapshot($item, $dupTitleSiblings, $dupDescSiblings, $redirectByPath);
            $seenPaths[$this->pathOf((string) ($item['url'] ?? ''))] = true;
        }
        // Pages that only show up via duplicate tags or a redirect chain
        // (decent onpage_score otherwise, so on_page/pages didn't surface
        // them) still deserve their own snapshot, up to the same bound.
        $extra = array_merge(array_keys($dupTitleSiblings), array_keys($dupDescSiblings), array_keys($redirectByPath));
        foreach (array_unique($extra) as $path) {
            if (isset($seenPaths[$path]) || count($seenPaths) >= $maxIssuePages) {
                continue;
            }
            $snapshots[] = new Snapshot('page', $path, [
                'onpage_score' => 100, 'status_code' => 200, 'word_count' => 0, 'load_time_ms' => 0, 'issues_count' => (int) isset($dupTitleSiblings[$path]) + (int) isset($dupDescSiblings[$path]) + (int) isset($redirectByPath[$path]),
            ], [
                'url' => rtrim('https://' . $this->ourDomain(), '/') . $path,
                'duplicate_title_siblings' => $dupTitleSiblings[$path] ?? [],
                'duplicate_description_siblings' => $dupDescSiblings[$path] ?? [],
                'redirect' => $redirectByPath[$path] ?? null,
                'failed_checks' => [],
            ]);
            $seenPaths[$path] = true;
        }

        return $snapshots;
    }

    public function findings(): array
    {
        $domain = $this->ourDomain();
        $findings = [];

        $nowSummary = $this->latest('summary', $domain);
        $prevSummary = $this->previous('summary', $domain);
        if ($nowSummary && $prevSummary) {
            $scoreDrop = (float) $this->config('score_drop_threshold', 3);
            $now = (float) ($nowSummary['metrics']['onpage_score'] ?? 0);
            $prev = (float) ($prevSummary['metrics']['onpage_score'] ?? 0);
            if ($prev - $now >= $scoreDrop) {
                $findings[] = $this->finding('score_drop', Finding::WARN, 'On-page score dropped', sprintf('Site-wide on-page score fell from %.1f to %.1f.', $prev, $now), $domain, 'score_drop',
                    ['onpage_score' => ['prev' => $prev, 'now' => $now]]);
            }

            $shrinkPct = (float) $this->config('crawl_shrink_pct', 0.10);
            $nowCrawled = (int) ($nowSummary['metrics']['pages_crawled'] ?? 0);
            $prevCrawled = (int) ($prevSummary['metrics']['pages_crawled'] ?? 0);
            if ($prevCrawled > 0 && $nowCrawled < $prevCrawled * (1 - $shrinkPct)) {
                $findings[] = $this->finding('crawl_shrunk', Finding::WARN, 'Fewer pages found by the crawl', sprintf('The crawl reached %d pages, down from %d.', $nowCrawled, $prevCrawled), $domain, 'crawl_shrunk',
                    ['pages_crawled' => ['prev' => $prevCrawled, 'now' => $nowCrawled]]);
            }

            $nowBroken = (int) ($nowSummary['metrics']['is_4xx'] ?? 0) + (int) ($nowSummary['metrics']['is_5xx'] ?? 0);
            $prevBroken = (int) ($prevSummary['metrics']['is_4xx'] ?? 0) + (int) ($prevSummary['metrics']['is_5xx'] ?? 0);
            if ($nowBroken > $prevBroken) {
                $findings[] = $this->finding('broken_pages_increase', Finding::CRITICAL, 'More broken pages than last crawl', sprintf('%d pages now return a 4xx/5xx, up from %d.', $nowBroken, $prevBroken), $domain, 'broken_pages_increase',
                    ['broken_pages' => ['prev' => $prevBroken, 'now' => $nowBroken]]);
            }
        }

        $candidates = [];
        foreach ($this->latestSet('page') as $path => $snap) {
            $failed = array_flip((array) ($snap['payload']['failed_checks'] ?? []));
            $score = (float) ($snap['metrics']['onpage_score'] ?? 100);
            $statusCode = (int) ($snap['metrics']['status_code'] ?? 200);

            if ($statusCode >= 400 || isset($failed['is_4xx_code']) || isset($failed['is_5xx_code']) || isset($failed['is_broken'])) {
                $candidates[] = [Finding::CRITICAL, 100 - $score + 50, $this->finding('broken', Finding::CRITICAL, "Broken page: {$path}", "Status code {$statusCode}.", $path, 'broken',
                    ['status_code' => ['prev' => null, 'now' => $statusCode]])];

                continue; // a 4xx/5xx page's other checks are noise until it's fixed
            }

            $redirect = $snap['payload']['redirect'] ?? null;
            if (is_array($redirect) && ! empty($redirect['hops'])) {
                $len = count($redirect['hops']);
                $title = ($redirect['is_loop'] ?? false) ? "Redirect loop: {$path}" : "Redirect chain: {$path}";
                $candidates[] = [Finding::CRITICAL, 40 + $len, $this->finding('redirect_chain', Finding::CRITICAL, $title, "{$len} hop(s) before the final destination.", $path, 'redirect_chain',
                    [], ['type' => 'reindex', 'url' => rtrim('https://' . $domain, '/') . $path])];
            }

            $dupTitle = (array) ($snap['payload']['duplicate_title_siblings'] ?? []);
            if ($dupTitle !== []) {
                $candidates[] = [Finding::WARN, 20 + count($dupTitle), $this->finding('duplicate_title', Finding::WARN, "Duplicate title: {$path}", 'Shares its title tag with ' . count($dupTitle) . ' other page(s): ' . implode(', ', array_slice($dupTitle, 0, 5)), $path, 'duplicate_title',
                    [], ['type' => 'title_meta', 'path' => $path])];
            }
            $dupDesc = (array) ($snap['payload']['duplicate_description_siblings'] ?? []);
            if ($dupDesc !== []) {
                $candidates[] = [Finding::WARN, 20 + count($dupDesc), $this->finding('duplicate_description', Finding::WARN, "Duplicate description: {$path}", 'Shares its meta description with ' . count($dupDesc) . ' other page(s): ' . implode(', ', array_slice($dupDesc, 0, 5)), $path, 'duplicate_description',
                    [], ['type' => 'title_meta', 'path' => $path])];
            }
            if (isset($failed['no_title'])) {
                $candidates[] = [Finding::WARN, 25, $this->finding('no_title', Finding::WARN, "Missing title: {$path}", 'No title tag.', $path, 'no_title', [], ['type' => 'title_meta', 'path' => $path])];
            }
            if (isset($failed['no_description'])) {
                $candidates[] = [Finding::WARN, 22, $this->finding('no_description', Finding::WARN, "Missing meta description: {$path}", 'No meta description.', $path, 'no_description', [], ['type' => 'title_meta', 'path' => $path])];
            }
            if (isset($failed['low_content_rate']) || isset($failed['low_character_count'])) {
                $words = (int) ($snap['metrics']['word_count'] ?? 0);
                $candidates[] = [Finding::WARN, 15, $this->finding('thin_content', Finding::WARN, "Thin content: {$path}", "Only {$words} words on the page.", $path, 'thin_content', [], ['type' => 'content_refresh', 'path' => $path])];
            }
            if (isset($failed['no_h1_tag'])) {
                $candidates[] = [Finding::INFO, 8, $this->finding('no_h1', Finding::INFO, "Missing H1: {$path}", 'No H1 heading found.', $path, 'no_h1')];
            }
            if (isset($failed['no_image_alt'])) {
                $candidates[] = [Finding::INFO, 5, $this->finding('no_image_alt', Finding::INFO, "Images missing alt text: {$path}", 'One or more images have no alt attribute.', $path, 'no_image_alt')];
            }
        }

        $rank = [Finding::CRITICAL => 0, Finding::WARN => 1, Finding::INFO => 2, Finding::WIN => 3];
        usort($candidates, fn ($a, $b) => [$rank[$a[0]], -$a[1]] <=> [$rank[$b[0]], -$b[1]]);

        $maxFindings = (int) $this->config('max_findings', 40);
        foreach (array_slice($candidates, 0, max(0, $maxFindings - count($findings))) as [, , $finding]) {
            $findings[] = $finding;
        }

        return $findings;
    }

    public function report(): array
    {
        $domain = $this->ourDomain();
        $now = $this->latest('summary', $domain);
        $prev = $this->previous('summary', $domain);
        if (! $now) {
            return ['tiles' => [], 'tables' => [], 'note' => 'No on-page crawl has run yet.'];
        }
        $m = $now['metrics'];
        $p = $prev['metrics'] ?? [];
        $tile = fn (string $label, string $key, string $unit = '', string $good = 'up') => [
            'label' => $label, 'value' => $m[$key] ?? null, 'prev' => $p[$key] ?? null, 'unit' => $unit, 'good' => $good,
        ];

        $rows = $this->latestSet('page')
            ->sortBy(fn ($s) => $s['metrics']['onpage_score'] ?? 100)
            ->take(12)
            ->map(function ($s, $path) {
                $failed = (array) ($s['payload']['failed_checks'] ?? []);
                $extra = array_filter([
                    ! empty($s['payload']['duplicate_title_siblings'] ?? []) ? 'duplicate title' : null,
                    ! empty($s['payload']['duplicate_description_siblings'] ?? []) ? 'duplicate description' : null,
                    ! empty($s['payload']['redirect']['hops'] ?? []) ? 'redirect chain' : null,
                ]);
                $issues = array_slice(array_merge($extra, $failed), 0, 4);

                return [$path, $s['metrics']['onpage_score'] ?? null, implode(', ', $issues) ?: '—'];
            })->values()->all();

        return [
            'tiles' => [
                $tile('On-page score', 'onpage_score', '/100'),
                $tile('Pages crawled', 'pages_crawled'),
                $tile('Pages with issues', 'pages_with_issues', '', 'down'),
                ['label' => 'Broken pages', 'value' => ($m['is_4xx'] ?? 0) + ($m['is_5xx'] ?? 0), 'prev' => isset($p['is_4xx']) ? ($p['is_4xx'] + ($p['is_5xx'] ?? 0)) : null, 'good' => 'down'],
                $tile('Duplicate titles', 'duplicate_title', '', 'down'),
            ],
            'tables' => [
                ['title' => 'Top issue pages', 'columns' => ['Page', 'Score', 'Issues'], 'rows' => $rows],
            ],
            'note' => sprintf('On-page crawl of %s on %s — %d pages crawled, score %.1f/100.', $domain, $now['taken_on'], $m['pages_crawled'] ?? 0, $m['onpage_score'] ?? 0),
        ];
    }

    /** @return array<int, array{accumulator: string, total_count: int, pages: array}> */
    private function duplicateGroups(string $id, string $type, int $limit): array
    {
        $data = $this->dfs->request('POST', '/on_page/duplicate_tags', [['id' => $id, 'type' => $type, 'limit' => $limit]]);

        return (array) (DataForSeoService::resultOf($data)[0]['items'] ?? []);
    }

    /** path => [sibling paths sharing the tag], from duplicate_tags groups. */
    private function siblingsByPath(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            $urls = array_values(array_filter(array_map(fn ($p) => (string) ($p['url'] ?? ''), (array) ($group['pages'] ?? []))));
            $paths = array_values(array_unique(array_map(fn ($u) => $this->pathOf($u), $urls)));
            foreach ($paths as $path) {
                $out[$path] = array_values(array_diff($paths, [$path]));
            }
        }

        return $out;
    }

    private function pageSnapshot(array $item, array $dupTitleSiblings, array $dupDescSiblings, array $redirectByPath): Snapshot
    {
        $url = (string) ($item['url'] ?? '');
        $path = $this->pathOf($url);
        $meta = (array) ($item['meta'] ?? []);
        $checks = (array) ($item['checks'] ?? []);
        $failed = array_keys(array_filter($checks, fn ($v) => $v === true));
        $htagsH1 = (array) ($meta['htags']['h1'] ?? []);

        return new Snapshot('page', $path, [
            'onpage_score' => round((float) ($item['onpage_score'] ?? 0), 2),
            'status_code' => (int) ($item['status_code'] ?? 0),
            'word_count' => (int) ($meta['plain_text_word_count'] ?? 0),
            'load_time_ms' => (int) ($item['page_timing']['duration_time'] ?? 0),
            'title_length' => mb_strlen((string) ($meta['title'] ?? '')),
            'description_length' => mb_strlen((string) ($meta['description'] ?? '')),
            'h1_count' => count($htagsH1),
            'issues_count' => count($failed),
        ], [
            'url' => $url,
            'title' => $meta['title'] ?? null,
            'description' => $meta['description'] ?? null,
            'failed_checks' => $failed,
            'duplicate_title_siblings' => $dupTitleSiblings[$path] ?? [],
            'duplicate_description_siblings' => $dupDescSiblings[$path] ?? [],
            'redirect' => $redirectByPath[$path] ?? null,
        ]);
    }

    private function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return $path !== null && $path !== '' ? $path : '/';
    }
}
