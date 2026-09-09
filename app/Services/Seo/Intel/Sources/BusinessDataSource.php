<?php

namespace App\Services\Seo\Intel\Sources;

use App\Services\DataForSeoService;
use App\Services\Seo\Intel\Finding;
use App\Services\Seo\Intel\IntelSource;
use App\Services\Seo\Intel\Snapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Our Google Business Profile versus the local competitors' profiles —
 * review velocity, rating and the attributes the 3-pack is actually decided
 * on. A remodeling job is bought on trust before a single page is read, and
 * the map pack (not organic ten-blue-links) is where most "kitchen remodeler
 * near me" clicks land — so an unclaimed profile, a rating slip, or falling
 * behind local competitors on reviews costs leads long before rankings move.
 *
 * Endpoints (DataForSEO Business Data API, verified against the v3 docs):
 *  - POST business_data/google/my_business_info/live — our own profile as
 *    Google shows it for `keyword` near center(): rating, claim status,
 *    category, attributes, hours, photos. ~$0.0054/call.
 *  - POST business_data/business_listings/search/live — every nearby
 *    listing in our categories within a radius, ordered by review count.
 *    ~$0.012/call + $0.00036/result (limit results stored). Its
 *    location_coordinate radius is genuinely km-scaled per the docs
 *    ("the value of radius is specified in kilometres (km)", 1-100000).
 *    my_business_info and reviews/task_post also take a "lat,lng,radius"
 *    location_coordinate, but it is a *different*, much finer-grained field:
 *    the docs give its bounds as 199.9-199999 with no km wording (labelled
 *    "(mm)" in the raw HTML, though a metres reading fits the docs' own
 *    example of radius=200 better) — not interchangeable with the listings
 *    radius, so it has its own knob (profile_radius_m) clamped to that range.
 *  - POST business_data/google/reviews/task_post + GET …/task_get/{id} —
 *    our own reviews (task-based, ~$0.00075 per 10 reviews returned):
 *    velocity, unanswered reviews, and any recent low rating.
 *
 * Cost per run ≈ estimateCost() (~$0.06 at the default knobs), capped by
 * config('max_cost', 0.3) — collect() stops issuing calls once spent
 * exceeds the cap and returns whatever snapshots it already has.
 */
class BusinessDataSource extends IntelSource
{
    public function family(): string
    {
        return 'business_data';
    }

    public function label(): string
    {
        return 'Business Data — GBP profile & local listings';
    }

    public function estimateCost(): float
    {
        $limit = (int) $this->config('listing_limit', 100);
        $depth = (int) $this->config('review_depth', 100);
        $profile = 0.0054;
        $listings = 0.012 + $limit * 0.00036;
        $reviews = ceil($depth / 10) * 0.00075;
        // Competitor review teardown (monthly): a reviews task per top competitor.
        $reviews += max(0, (int) $this->config('competitor_reviews', 3)) * ceil((int) $this->config('competitor_review_depth', 40) / 10) * 0.00075;

        return round($profile + $listings + $reviews, 4);
    }

    public function collect(): array
    {
        $spentAtStart = $this->dfs->spent();
        $maxCost = (float) $this->config('max_cost', 0.3);
        $snapshots = [];

        if ($this->dfs->spent() - $spentAtStart < $maxCost) {
            if ($profile = $this->collectProfile()) {
                $snapshots[] = $profile;
            }
        }

        if ($this->dfs->spent() - $spentAtStart < $maxCost) {
            $snapshots = array_merge($snapshots, $this->collectListings());
        }

        if ($this->dfs->spent() - $spentAtStart < $maxCost) {
            if ($reviews = $this->collectReviews()) {
                $snapshots[] = $reviews;
            }
        }

        // Competitor review teardown, monthly: what the top competitors are
        // praised for, next to what our own reviews say.
        if ($this->dfs->spent() - $spentAtStart < $maxCost && $this->reviewThemesDue()) {
            $snapshots = array_merge($snapshots, $this->collectReviewThemes($snapshots));
        }

        if ($snapshots === [] && $this->dfs->getLastError()) {
            throw new \RuntimeException('BusinessDataSource: ' . $this->dfs->getLastError());
        }

        return $snapshots;
    }

    public function findings(): array
    {
        return array_merge(
            $this->profileFindings(),
            $this->listingFindings(),
            $this->reviewFindings(),
            $this->reviewThemeFindings(),
        );
    }

    public function report(): array
    {
        $subject = $this->profileSubject();
        $profNow = $this->latest('profile', $subject);
        $profPrev = $this->previous('profile', $subject);
        $reviewsNow = $this->latest('reviews', $subject);
        $latest = $this->latestSet('listing');

        [$subjects, $ourSubject] = $this->rankedListings($latest);
        $rank = $ourSubject !== null ? (array_search($ourSubject, $subjects, true) + 1) : null;

        $tiles = [
            ['label' => 'GBP rating', 'value' => $profNow['metrics']['rating'] ?? null, 'prev' => $profPrev['metrics']['rating'] ?? null, 'unit' => '★', 'good' => 'up'],
            ['label' => 'GBP reviews', 'value' => $profNow['metrics']['votes'] ?? null, 'prev' => $profPrev['metrics']['votes'] ?? null, 'good' => 'up'],
            ['label' => 'Reviews (30d)', 'value' => $reviewsNow['metrics']['last_30_days'] ?? null, 'good' => 'up'],
            ['label' => 'Unanswered reviews', 'value' => $reviewsNow['metrics']['unanswered'] ?? null, 'good' => 'down'],
            ['label' => 'Rank by review count', 'value' => $rank, 'unit' => $subjects ? ('of ' . count($subjects)) : null, 'good' => 'down'],
        ];

        $listingRows = collect($subjects)->take(12)->map(function ($s) use ($latest) {
            $r = $latest[$s];

            return [$r['payload']['title'] ?? $s, $r['metrics']['rating'] ?? null, $r['metrics']['votes'] ?? 0, ! empty($r['metrics']['is_claimed']) ? 'Yes' : 'No'];
        })->values()->all();

        $missing = $this->missingAttributes($latest, $subjects, $ourSubject);
        $attrRows = collect($missing)->map(fn ($a) => [$a])->values()->all();

        $tables = [
            ['title' => 'Nearby listings by review count', 'columns' => ['Business', 'Rating', 'Reviews', 'Claimed'], 'rows' => $listingRows],
        ];
        if ($attrRows !== []) {
            $tables[] = ['title' => 'Attributes most local competitors show that we do not', 'columns' => ['Attribute'], 'rows' => $attrRows];
        }
        $categoryGap = $this->missingCategories($latest, $subjects, $ourSubject);
        if ($categoryGap !== []) {
            $tables[] = ['title' => 'Categories most local competitors list that we do not', 'columns' => ['Category'], 'rows' => array_map(fn ($c) => [$c], $categoryGap)];
        }
        $themeRows = $this->latestSet('review_themes')->sortByDesc(fn ($s) => (int) ($s['metrics']['reviews_analysed'] ?? 0))
            ->map(fn ($s, $subject) => [(string) ($s['payload']['name'] ?? $subject), implode(', ', (array) ($s['payload']['praised'] ?? [])), implode(', ', (array) ($s['payload']['complaints'] ?? []))])
            ->values()->all();
        if ($themeRows !== []) {
            $tables[] = ['title' => 'What the reviews praise (ours and the top competitors\')', 'columns' => ['Business', 'Praised for', 'Complaints'], 'rows' => $themeRows];
        }

        $note = $profNow
            ? sprintf('GBP profile and %d nearby listing(s) measured %s.', count($subjects), $profNow['taken_on'])
            : 'No business-data run has completed yet.';

        return ['tiles' => $tiles, 'tables' => $tables, 'note' => $note];
    }

    // --- collection -----------------------------------------------------

    protected function collectProfile(): ?Snapshot
    {
        [$lat, $lng] = $this->center();
        $env = $this->dfs->request('POST', '/business_data/google/my_business_info/live', [[
            'keyword' => $this->keyword(),
            'location_coordinate' => sprintf('%.6f,%.6f,%d', $lat, $lng, $this->pointRadius()),
            'language_code' => 'en',
        ]]);
        $row = DataForSeoService::resultOf($env)[0] ?? null;
        $item = is_array($row) ? ($row['items'][0] ?? null) : null;
        if (! is_array($item)) {
            return null;
        }

        $available = $this->flattenAttributes($item['attributes']['available_attributes'] ?? null);
        $categories = array_values(array_filter(array_merge([(string) ($item['category'] ?? '')], (array) ($item['additional_categories'] ?? []))));

        $metrics = [
            'rating' => isset($item['rating']['value']) ? (float) $item['rating']['value'] : null,
            'votes' => isset($item['rating']['votes_count']) ? (int) $item['rating']['votes_count'] : 0,
            'is_claimed' => empty($item['is_claimed']) ? 0 : 1,
            'total_photos' => isset($item['total_photos']) ? (int) $item['total_photos'] : null,
            'description_length' => mb_strlen((string) ($item['description'] ?? '')),
            'attributes_available' => count($available),
        ];
        $payload = [
            'place_id' => $item['place_id'] ?? null,
            'cid' => $item['cid'] ?? null,
            'title' => $item['title'] ?? null,
            'category' => $item['category'] ?? null,
            'categories' => $categories,
            'available_attributes' => $available,
            'unavailable_attributes' => $this->flattenAttributes($item['attributes']['unavailable_attributes'] ?? null),
            'work_time' => $item['work_time']['work_hours']['current_status'] ?? null,
            'price_level' => $item['price_level'] ?? null,
            'address' => $item['address'] ?? ($item['address_info'] ?? null),
            'url' => $item['url'] ?? null,
            'domain' => $item['domain'] ?? null,
        ];

        return new Snapshot('profile', $this->profileSubject(), $metrics, $payload);
    }

    /** @return Snapshot[] */
    protected function collectListings(): array
    {
        [$lat, $lng] = $this->center();
        $categories = (array) $this->config('listing_categories', ['kitchen_remodeler', 'bathroom_remodeler', 'remodeler', 'general_contractor']);
        $env = $this->dfs->request('POST', '/business_data/business_listings/search/live', [[
            'categories' => $categories,
            'location_coordinate' => sprintf('%.6f,%.6f,%d', $lat, $lng, (int) $this->config('listing_radius_km', 15)),
            'limit' => (int) $this->config('listing_limit', 100),
            'order_by' => ['rating.votes_count,desc'],
        ]]);
        $row = DataForSeoService::resultOf($env)[0] ?? null;
        $items = is_array($row) ? (array) ($row['items'] ?? []) : [];

        $out = [];
        foreach (array_slice($items, 0, (int) $this->config('listing_store_top_n', 25)) as $it) {
            $pid = (string) ($it['place_id'] ?? '');
            if ($pid === '') {
                continue;
            }
            // The category search also returns retailers that list "Kitchen
            // remodeler" as a side category (IKEA, countertop showrooms,
            // plumbers). Keep businesses whose primary category is a trade.
            if (! self::isTradeCategory((string) ($it['category'] ?? ''))) {
                continue;
            }
            $available = $this->flattenAttributes($it['attributes']['available_attributes'] ?? null);
            $metrics = [
                'rating' => isset($it['rating']['value']) ? (float) $it['rating']['value'] : null,
                'votes' => isset($it['rating']['votes_count']) ? (int) $it['rating']['votes_count'] : 0,
                'is_claimed' => empty($it['is_claimed']) ? 0 : 1,
                'total_photos' => isset($it['total_photos']) ? (int) $it['total_photos'] : null,
                'attributes_available' => count($available),
            ];
            $payload = [
                'title' => $it['title'] ?? null,
                'category' => $it['category'] ?? null,
                'additional_categories' => $it['additional_categories'] ?? [],
                'available_attributes' => $available,
                'phone_present' => ! empty($it['phone']),
                'work_time_present' => ! empty($it['work_time']),
                'url' => $it['url'] ?? null,
                'domain' => $it['domain'] ?? null,
            ];
            $out[] = new Snapshot('listing', $pid, $metrics, $payload);
        }

        return $out;
    }

    protected function collectReviews(): ?Snapshot
    {
        [$lat, $lng] = $this->center();
        $depth = (int) $this->config('review_depth', 100);
        $placeId = (string) config('services.google.business_profile.place_id');
        $task = [
            'language_code' => 'en',
            'location_coordinate' => sprintf('%.6f,%.6f,%d', $lat, $lng, $this->pointRadius()),
            'depth' => $depth,
            'sort_by' => 'newest',
        ];
        $task[$placeId !== '' ? 'place_id' : 'keyword'] = $placeId !== '' ? $placeId : $this->keyword();

        $result = $this->fetchReviewTask($task);
        if (! is_array($result)) {
            return null;
        }

        $now = now();
        $items = (array) ($result['items'] ?? []);
        $last30 = 0;
        $last90 = 0;
        $ratingSum90 = 0.0;
        $ratingCount90 = 0;
        $unanswered = [];
        $lowRecent = [];
        $latestDate = null;

        foreach ($items as $rv) {
            $ts = (string) ($rv['timestamp'] ?? '');
            $date = $ts !== '' ? Carbon::parse($ts) : null;
            $rating = isset($rv['rating']['value']) ? (float) $rv['rating']['value'] : null;
            $answered = ! empty($rv['owner_answer']);

            if ($date) {
                if ($latestDate === null || $date->gt($latestDate)) {
                    $latestDate = $date;
                }
                $daysAgo = $date->diffInDays($now);
                if ($daysAgo <= 30) {
                    $last30++;
                    if ($rating !== null && $rating <= 2) {
                        $lowRecent[] = ['date' => $date->toDateString(), 'rating' => $rating, 'excerpt' => mb_substr((string) ($rv['review_text'] ?? ''), 0, 300), 'profile_name' => $rv['profile_name'] ?? null];
                    }
                }
                if ($daysAgo <= 90) {
                    $last90++;
                    if ($rating !== null) {
                        $ratingSum90 += $rating;
                        $ratingCount90++;
                    }
                }
            }
            if (! $answered) {
                $unanswered[] = ['date' => $date?->toDateString(), 'rating' => $rating];
            }
        }

        $metrics = [
            'reviews_total' => isset($result['reviews_count']) ? (int) $result['reviews_count'] : count($items),
            'last_30_days' => $last30,
            'last_90_days' => $last90,
            'avg_rating_90d' => $ratingCount90 ? round($ratingSum90 / $ratingCount90, 2) : null,
            'unanswered' => count($unanswered),
            'latest_review_days_ago' => $latestDate ? $latestDate->diffInDays($now) : null,
        ];
        $payload = [
            'latest_review_date' => $latestDate?->toDateString(),
            'unanswered_list' => array_slice($unanswered, 0, 20),
            'low_rating_recent' => array_slice($lowRecent, 0, (int) $this->config('max_findings', 5)),
        ];

        return new Snapshot('reviews', $this->profileSubject(), $metrics, $payload);
    }

    // --- findings ---------------------------------------------------------

    /** @return Finding[] */
    protected function profileFindings(): array
    {
        $subject = $this->profileSubject();
        $now = $this->latest('profile', $subject);
        if (! $now) {
            return [];
        }
        $prev = $this->previous('profile', $subject);
        $out = [];

        if ((int) ($now['metrics']['is_claimed'] ?? 1) === 0) {
            $out[] = $this->finding('unclaimed', Finding::CRITICAL, 'Google Business Profile shows as unclaimed',
                'The Business Data API sees this listing as unclaimed. An unclaimed profile cannot be edited (hours, photos, responses) and reads as less trustworthy in the map pack.',
                $subject, null, [], ['type' => 'reindex', 'url' => (string) ($now['payload']['url'] ?? config('app.url'))]);
        }

        if ($prev && isset($now['metrics']['rating'], $prev['metrics']['rating'])) {
            $drop = (float) $prev['metrics']['rating'] - (float) $now['metrics']['rating'];
            if ($drop >= 0.1) {
                $out[] = $this->finding('rating_drop', Finding::CRITICAL, 'GBP rating dropped',
                    sprintf('Rating fell from %.1f to %.1f.', $prev['metrics']['rating'], $now['metrics']['rating']),
                    $subject, null, ['rating' => ['prev' => $prev['metrics']['rating'], 'now' => $now['metrics']['rating']]]);
            }
        }

        if ($prev && isset($now['metrics']['votes'], $prev['metrics']['votes']) && $now['metrics']['votes'] > $prev['metrics']['votes']) {
            $out[] = $this->finding('reviews_up', Finding::WIN, 'GBP review count grew',
                sprintf('%d to %d reviews since the last run.', $prev['metrics']['votes'], $now['metrics']['votes']),
                $subject, null, ['votes' => ['prev' => $prev['metrics']['votes'], 'now' => $now['metrics']['votes']]]);
        }

        $accepted = (array) $this->config('accepted_categories', ['Kitchen remodeler', 'Bathroom remodeler', 'Remodeler']);
        $category = (string) ($now['payload']['category'] ?? '');
        if ($category !== '' && ! in_array($category, $accepted, true)) {
            $out[] = $this->finding('category_mismatch', Finding::INFO, 'GBP primary category is not a remodeling category',
                "Google lists the primary category as \"{$category}\".", $subject);
        }

        return $out;
    }

    /** @return Finding[] */
    protected function listingFindings(): array
    {
        $latest = $this->latestSet('listing');
        if ($latest->isEmpty()) {
            return [];
        }
        $previous = $this->previousSet('listing');
        [$subjects, $ourSubject] = $this->rankedListings($latest);
        $out = [];

        // Our rank by review count, always reported once there is data.
        $rank = $ourSubject !== null ? array_search($ourSubject, $subjects, true) + 1 : null;
        $ourVotes = $ourSubject !== null ? (int) ($latest[$ourSubject]['metrics']['votes'] ?? 0) : 0;
        $fifthVotes = isset($subjects[4]) ? (int) ($latest[$subjects[4]]['metrics']['votes'] ?? 0) : null;
        $top3 = collect(array_slice($subjects, 0, 3))->map(fn ($s) => ($latest[$s]['payload']['title'] ?? $s) . ' (' . ($latest[$s]['metrics']['votes'] ?? 0) . ')')->implode(', ');
        $severity = Finding::INFO;
        if (($rank === null || $rank > 5) && $fifthVotes !== null && $ourVotes > 0 && $fifthVotes <= $ourVotes * 2) {
            $severity = Finding::WARN;
        }
        $title = $rank === null ? 'Not visible among the top local listings by review count' : "Ranked #{$rank} of " . count($subjects) . ' local listings by review count';
        $out[] = $this->finding('review_rank', $severity, $title, "Top 3 by reviews: {$top3}.", $ourSubject ?? $this->profileSubject(), null,
            $rank !== null ? ['rank' => ['prev' => null, 'now' => $rank]] : []);

        // Attributes most of the top 10 show that we do not.
        $missing = $this->missingAttributes($latest, $subjects, $ourSubject);
        if ($missing !== []) {
            $out[] = $this->finding('missing_attributes', Finding::INFO, 'Attributes most local competitors show that we do not',
                implode(', ', $missing) . '.', $this->profileSubject());
        }

        // Categories most of the top 10 list that our profile does not.
        $categoryGap = $this->missingCategories($latest, $subjects, $ourSubject);
        if ($categoryGap !== []) {
            $out[] = $this->finding('category_gap', Finding::INFO, 'Categories most local competitors list that we do not',
                implode(', ', $categoryGap) . '. Add the ones that fit as additional categories on the Business Profile (config/gbp-services.php pushes them weekly).', $this->profileSubject());
        }

        if ($previous->isNotEmpty()) {
            // Competitors that gained reviews since the previous run.
            $gains = [];
            foreach ($latest as $s => $row) {
                if ($s === $ourSubject) {
                    continue;
                }
                $prevRow = $previous[$s] ?? null;
                if (! $prevRow) {
                    continue;
                }
                $gain = (int) ($row['metrics']['votes'] ?? 0) - (int) ($prevRow['metrics']['votes'] ?? 0);
                if ($gain >= 10) {
                    $gains[$s] = $gain;
                }
            }
            arsort($gains);
            foreach (array_slice($gains, 0, (int) $this->config('max_findings', 5), true) as $s => $gain) {
                $name = $latest[$s]['payload']['title'] ?? $s;
                $prevVotes = (int) $latest[$s]['metrics']['votes'] - $gain;
                $out[] = $this->finding('competitor_review_gain', Finding::INFO, "{$name} gained reviews",
                    sprintf('%s went from %d to %d reviews since the last run.', $name, $prevVotes, $latest[$s]['metrics']['votes']),
                    (string) $s, null, ['votes' => ['prev' => $prevVotes, 'now' => $latest[$s]['metrics']['votes']]]);
            }

            // New entrants into the top 10 by review count.
            [$prevSubjects] = $this->rankedListings($previous);
            $newEntrants = array_values(array_diff(array_slice($subjects, 0, 10), array_slice($prevSubjects, 0, 10), [$ourSubject]));
            foreach (array_slice($newEntrants, 0, (int) $this->config('max_findings', 5)) as $s) {
                $name = $latest[$s]['payload']['title'] ?? $s;
                $out[] = $this->finding('new_top10_listing', Finding::INFO, "{$name} entered the top 10 local listings by review count", '', (string) $s);
            }
        }

        return $out;
    }

    /** @return Finding[] */
    protected function reviewFindings(): array
    {
        $subject = $this->profileSubject();
        $now = $this->latest('reviews', $subject);
        if (! $now) {
            return [];
        }
        $out = [];

        $unanswered = (int) ($now['metrics']['unanswered'] ?? 0);
        if ($unanswered >= 1) {
            $dates = collect((array) ($now['payload']['unanswered_list'] ?? []))
                ->map(fn ($u) => ($u['date'] ?? '?') . ' (' . ($u['rating'] ?? '?') . '★)')
                ->take(10)->implode(', ');
            $out[] = $this->finding('unanswered_reviews', Finding::WARN, sprintf('%d review(s) awaiting an owner response', $unanswered),
                $dates, $subject, null, ['unanswered' => ['prev' => null, 'now' => $unanswered]]);
        }

        $silenceDays = (int) $this->config('review_silence_days', 45);
        $daysAgo = $now['metrics']['latest_review_days_ago'] ?? null;
        if ($daysAgo !== null && $daysAgo >= $silenceDays) {
            $out[] = $this->finding('review_silence', Finding::WARN, "No new review in {$daysAgo} days",
                'Last review was on ' . ($now['payload']['latest_review_date'] ?? 'unknown') . '.', $subject);
        }

        foreach ((array) ($now['payload']['low_rating_recent'] ?? []) as $rv) {
            $key = 'low:' . ($rv['date'] ?? '') . ':' . ($rv['profile_name'] ?? '');
            $out[] = $this->finding('low_rating_recent', Finding::CRITICAL, sprintf('%s-star review in the last 30 days', $rv['rating'] ?? '?'),
                mb_substr((string) ($rv['excerpt'] ?? ''), 0, 120), $subject, $key, ['rating' => ['prev' => null, 'now' => $rv['rating'] ?? null]]);
        }

        if ($velocity = $this->velocityFinding($subject)) {
            $out[] = $velocity;
        }

        return $out;
    }

    /** Our review velocity vs. the median of the top-5 local listings', when both runs have listing data. */
    protected function velocityFinding(string $subject): ?Finding
    {
        $profNow = $this->latest('profile', $subject);
        $profPrev = $this->previous('profile', $subject);
        $listPrev = $this->previousSet('listing');
        $listNow = $this->latestSet('listing');
        if (! $profNow || ! $profPrev || $listPrev->isEmpty() || $listNow->isEmpty()) {
            return null;
        }
        $days = max(1, Carbon::parse($profPrev['taken_on'])->diffInDays(Carbon::parse($profNow['taken_on'])));
        $ourVelocity = ((int) $profNow['metrics']['votes'] - (int) $profPrev['metrics']['votes']) / $days;

        [$subjects] = $this->rankedListings($listNow);
        $velocities = [];
        foreach (array_slice($subjects, 0, 5) as $s) {
            $prevRow = $listPrev[$s] ?? null;
            if (! $prevRow) {
                continue;
            }
            $velocities[] = ((int) $listNow[$s]['metrics']['votes'] - (int) $prevRow['metrics']['votes']) / $days;
        }
        if ($velocities === []) {
            return null;
        }
        sort($velocities);
        $mid = (int) floor(count($velocities) / 2);
        $median = count($velocities) % 2 ? $velocities[$mid] : ($velocities[$mid - 1] + $velocities[$mid]) / 2;
        if ($ourVelocity >= $median) {
            return null;
        }

        return $this->finding('velocity_low', Finding::INFO, 'Review velocity trails the local top 5',
            sprintf('Gaining ~%.2f reviews/day versus a median of %.2f/day among the top 5 local listings.', $ourVelocity, $median),
            $subject, null, ['velocity' => ['prev' => $median, 'now' => $ourVelocity]]);
    }

    // --- helpers ------------------------------------------------------

    /**
     * Categories (primary + additional) that at least category_threshold of
     * the top-10 competitors list and our own listing/profile does not, as
     * "Name (n of m)".
     */
    protected function missingCategories(Collection $latest, array $subjects, ?string $ourSubject): array
    {
        $norm = fn ($c) => mb_strtolower(trim((string) $c));
        $ours = [];
        $profile = $this->latest('profile', $this->profileSubject());
        $ourCats = array_merge(
            [(string) ($profile['payload']['category'] ?? '')],
            (array) ($profile['payload']['categories'] ?? []),
            $ourSubject !== null ? array_merge([(string) ($latest[$ourSubject]['payload']['category'] ?? '')], (array) ($latest[$ourSubject]['payload']['additional_categories'] ?? [])) : []
        );
        foreach ($ourCats as $c) {
            if ($norm($c) !== '') {
                $ours[$norm($c)] = true;
            }
        }
        $top = array_values(array_filter(array_slice($subjects, 0, 10), fn ($s) => $s !== $ourSubject));
        if (count($top) < 3) {
            return [];
        }
        $counts = [];
        foreach ($top as $s) {
            $cats = array_unique(array_filter(array_map($norm, array_merge([(string) ($latest[$s]['payload']['category'] ?? '')], (array) ($latest[$s]['payload']['additional_categories'] ?? [])))));
            foreach ($cats as $c) {
                $counts[$c] = ($counts[$c] ?? 0) + 1;
            }
        }
        arsort($counts);
        $threshold = (float) $this->config('category_threshold', 0.4);
        $out = [];
        foreach ($counts as $c => $n) {
            if ($n / count($top) >= $threshold && ! isset($ours[$c])) {
                $out[] = ucfirst($c) . " ({$n} of " . count($top) . ')';
            }
        }

        return array_slice($out, 0, 8);
    }

    // --- competitor review teardown ------------------------------------

    /** The theme vocabulary the summariser maps reviews onto, so businesses compare like for like. */
    public const REVIEW_THEMES = ['communication', 'on time', 'on budget', 'clean job site', 'craftsmanship', 'design help', 'problem solving', 'fair pricing', 'responsiveness', 'attention to detail', 'friendly crew', 'project management', 'warranty follow-up', 'trustworthy'];

    /** Post a reviews task and wait for it; the result row or null. */
    protected function fetchReviewTask(array $task): ?array
    {
        $id = $this->dfs->postTask('/business_data/google/reviews/task_post', $task);
        if ($id === null) {
            return null;
        }
        $result = $this->dfs->pollUntil(function () use ($id) {
            $env = $this->dfs->request('GET', "/business_data/google/reviews/task_get/{$id}");
            $row = DataForSeoService::resultOf($env)[0] ?? null;

            return is_array($row) ? $row : null;
        }, 120, 5);

        return is_array($result) ? $result : null;
    }

    /** Once every competitor_reviews_every_days, and only when the teardown is switched on. */
    protected function reviewThemesDue(): bool
    {
        if ((int) $this->config('competitor_reviews', 3) <= 0) {
            return false;
        }
        $last = $this->store->latestDay($this->family(), 'review_themes');

        return $last === null || Carbon::parse($last)->lte(now()->subDays((int) $this->config('competitor_reviews_every_days', 30)));
    }

    /**
     * Themes praised in the top competitors' reviews and in our own
     * (testimonials), one 'review_themes' snapshot per business.
     *
     * @param  Snapshot[]  $collected  this run's snapshots (the listings are read from here)
     * @return Snapshot[]
     */
    protected function collectReviewThemes(array $collected): array
    {
        $listings = collect($collected)->filter(fn (Snapshot $s) => $s->kind === 'listing')->mapWithKeys(fn (Snapshot $s) => [$s->subject => ['metrics' => $s->metrics, 'payload' => $s->payload]]);
        [$subjects, $ourSubject] = $this->rankedListings($listings);
        $top = array_slice(array_values(array_filter($subjects, fn ($s) => $s !== $ourSubject)), 0, (int) $this->config('competitor_reviews', 3));
        [$lat, $lng] = $this->center();
        $depth = (int) $this->config('competitor_review_depth', 40);
        $out = [];

        foreach ($top as $pid) {
            $name = (string) ($listings[$pid]['payload']['title'] ?? $pid);
            $result = $this->fetchReviewTask([
                'place_id' => $pid, 'language_code' => 'en', 'depth' => $depth, 'sort_by' => 'newest',
                'location_coordinate' => sprintf('%.6f,%.6f,%d', $lat, $lng, $this->pointRadius()),
            ]);
            $items = is_array($result) ? (array) ($result['items'] ?? []) : [];
            $texts = collect($items)->map(fn ($rv) => trim((string) ($rv['review_text'] ?? '')))->filter()->values()->all();
            if ($texts === []) {
                continue;
            }
            $themes = $this->summariseReviews($texts, $name);
            $ratings = collect($items)->map(fn ($rv) => $rv['rating']['value'] ?? null)->filter()->map(fn ($v) => (float) $v);
            $out[] = new Snapshot('review_themes', $pid, [
                'reviews_analysed' => count($texts), 'avg_rating' => $ratings->isNotEmpty() ? round($ratings->avg(), 2) : null,
                'praised_count' => count($themes['praised']), 'complaint_count' => count($themes['complaints']),
            ], ['name' => $name, 'is_us' => false] + $themes);
        }

        // Our own reviews: the testimonials we hold (no API cost).
        $ours = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('testimonials')) {
            $ours = \App\Support\Tenancy::table('testimonials')->where('is_hidden', false)->orderByDesc('review_date')->limit($depth)
                ->pluck('review_description')->map(fn ($t) => trim((string) $t))->filter()->values()->all();
        }
        if ($ours !== []) {
            $themes = $this->summariseReviews($ours, (string) config('brand.display_name', config('brand.name')));
            $out[] = new Snapshot('review_themes', $this->profileSubject(), [
                'reviews_analysed' => count($ours), 'avg_rating' => null, 'praised_count' => count($themes['praised']), 'complaint_count' => count($themes['complaints']),
            ], ['name' => (string) config('brand.display_name', config('brand.name')), 'is_us' => true] + $themes);
        }

        return $out;
    }

    /**
     * Map a set of reviews onto the fixed theme vocabulary (praised /
     * complained about) plus the words reviewers keep using. Without an AI
     * key the praised list stays empty and the run says so.
     *
     * @return array{praised: list<string>, complaints: list<string>, keywords: list<string>, note: ?string}
     */
    protected function summariseReviews(array $texts, string $who): array
    {
        $sample = implode("\n---\n", array_map(fn ($t) => mb_substr($t, 0, 600), array_slice($texts, 0, 40)));
        $prompt = implode("\n", [
            "Below are customer reviews of \"{$who}\", a remodeling contractor. Classify what reviewers PRAISE and what they COMPLAIN ABOUT using only these theme labels:",
            implode(', ', self::REVIEW_THEMES) . '.',
            'Return ONLY a JSON object: {"praised": [themes mentioned positively by at least two reviews, most common first], "complaints": [themes mentioned negatively], "keywords": [up to 8 short phrases reviewers repeat, e.g. "kitchen island", "on schedule"]}.',
            '', $sample,
        ]);
        $raw = app(\App\Services\AiContentService::class)->generateText($prompt, 800, 0.2);
        $data = $raw !== null ? json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', trim($raw)) ?? $raw), true) : null;
        if (! is_array($data)) {
            return ['praised' => [], 'complaints' => [], 'keywords' => [], 'note' => 'Reviews collected; theme classification needs the Gemini key.'];
        }
        $onVocab = fn ($list) => array_values(array_intersect(array_map(fn ($t) => mb_strtolower(trim((string) $t)), (array) $list), self::REVIEW_THEMES));

        return [
            'praised' => $onVocab($data['praised'] ?? []),
            'complaints' => $onVocab($data['complaints'] ?? []),
            'keywords' => array_values(array_filter(array_map(fn ($k) => mb_substr(trim((string) $k), 0, 40), (array) ($data['keywords'] ?? [])))),
            'note' => null,
        ];
    }

    /** Themes at least two competitors are praised for that our own reviews never earn. */
    protected function reviewThemeFindings(): array
    {
        $set = $this->latestSet('review_themes');
        if ($set->isEmpty()) {
            return [];
        }
        $ours = [];
        $counts = [];
        $by = [];
        foreach ($set as $subject => $s) {
            $praised = (array) ($s['payload']['praised'] ?? []);
            if (! empty($s['payload']['is_us'])) {
                $ours = $praised;
                continue;
            }
            foreach ($praised as $t) {
                $counts[$t] = ($counts[$t] ?? 0) + 1;
                $by[$t][] = (string) ($s['payload']['name'] ?? $subject);
            }
        }
        $gap = array_keys(array_filter($counts, fn ($n) => $n >= 2));
        $gap = array_values(array_diff($gap, $ours));
        if ($gap === []) {
            return [];
        }
        $detail = collect($gap)->map(fn ($t) => $t . ' (' . implode(', ', $by[$t]) . ')')->implode('; ');

        return [$this->finding('review_theme_gap', Finding::INFO, 'What competitors are praised for that our reviews never mention',
            $detail . '. Ask recent clients to mention these when they review, and make them visible on the site.', $this->profileSubject())];
    }

    /** The keyword my_business_info / reviews search for: our brand + home city. */
    protected function keyword(): string
    {
        $default = trim(sprintf('%s %s, %s', (string) config('brand.name'), (string) config('brand.city'), (string) config('brand.state')), ' ,');

        return (string) $this->config('keyword', $default);
    }

    /**
     * Radius (documented range 199.9-199999) for the location_coordinate used by
     * my_business_info/live and reviews/task_post — distinct from and much
     * smaller-scaled than business_listings' km radius (see class docblock).
     * Clamped so a misconfigured/omitted value can't fall below the API's
     * documented minimum and fail (or silently degrade) the call.
     */
    protected function pointRadius(): int
    {
        $value = (float) $this->config('profile_radius_m', 5000);

        return (int) round(max(199.9, min(199999, $value)));
    }

    /** Stable identity for our own profile/reviews snapshots: the configured GBP place id, else our domain. */
    protected function profileSubject(): string
    {
        $placeId = (string) config('services.google.business_profile.place_id');

        return $placeId !== '' ? $placeId : $this->ourDomain();
    }

    /** Flatten {category: [attr, ...], ...} (or null) into a unique flat list of attribute keys. */
    protected function flattenAttributes(mixed $grouped): array
    {
        if (! is_array($grouped)) {
            return [];
        }
        $out = [];
        foreach ($grouped as $list) {
            foreach ((array) $list as $attr) {
                $out[] = (string) $attr;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * A listing snapshot set ordered by review count (desc), plus which subject is us
     * — matched by domain or by the brand name appearing in the title, since a place
     * id is not guaranteed to match the configured one exactly.
     *
     * @return array{0: array<int, string>, 1: ?string}
     */
    protected function rankedListings(Collection $set): array
    {
        $ordered = $set->sortByDesc(fn ($row) => (int) ($row['metrics']['votes'] ?? 0));
        $subjects = $ordered->keys()->values()->all();

        $brand = mb_strtolower(preg_replace('/\s*&.*$/', '', (string) config('brand.name')) ?: 'business');
        $domain = $this->ourDomain();
        $ourSubject = null;
        foreach ($subjects as $s) {
            $payload = $set[$s]['payload'] ?? [];
            $rowDomain = mb_strtolower((string) ($payload['domain'] ?? ''));
            $title = mb_strtolower((string) ($payload['title'] ?? ''));
            if (($rowDomain !== '' && str_contains($rowDomain, $domain)) || ($brand !== '' && str_contains($title, $brand))) {
                $ourSubject = $s;
                break;
            }
        }

        return [$subjects, $ourSubject];
    }

    /** Attributes ≥50% of the top-10 listings show that our own profile/listing does not. */
    protected function missingAttributes(Collection $latest, array $subjects, ?string $ourSubject): array
    {
        $top10 = array_slice($subjects, 0, 10);
        if ($top10 === []) {
            return [];
        }
        $counts = [];
        foreach ($top10 as $s) {
            foreach ((array) ($latest[$s]['payload']['available_attributes'] ?? []) as $a) {
                $counts[$a] = ($counts[$a] ?? 0) + 1;
            }
        }
        $threshold = max(1, (int) ceil(count($top10) / 2));

        $ours = $ourSubject !== null
            ? (array) ($latest[$ourSubject]['payload']['available_attributes'] ?? [])
            : (array) ($this->latest('profile', $this->profileSubject())['payload']['available_attributes'] ?? []);

        $missing = [];
        foreach ($counts as $attr => $c) {
            if ($c >= $threshold && ! in_array($attr, $ours, true)) {
                $missing[] = $attr;
            }
        }

        return array_slice($missing, 0, (int) $this->config('max_findings', 8));
    }

    /** A Google primary category that means "someone who builds/remodels", not a store or an adjacent trade. */
    public static function isTradeCategory(string $category): bool
    {
        $c = mb_strtolower(trim($category));
        if ($c === '') {
            return true; // unknown: keep, the review-count rank is still informative
        }
        if (preg_match('/store|supplier|showroom|shop|warehouse|manufacturer|wholesaler|plumber|electrician|hvac|roofing|flooring|painter|furniture|appliance|architect|interior designer|cleaning|handyman/i', $c)) {
            return false;
        }

        return (bool) preg_match('/remodel|renovat|contractor|construction|builder|design.build|home improvement|kitchen|bathroom|basement|addition/i', $c);
    }
}
