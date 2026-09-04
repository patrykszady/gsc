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

        $id = $this->dfs->postTask('/business_data/google/reviews/task_post', $task);
        if ($id === null) {
            return null;
        }
        $result = $this->dfs->pollUntil(function () use ($id) {
            $env = $this->dfs->request('GET', "/business_data/google/reviews/task_get/{$id}");
            $row = DataForSeoService::resultOf($env)[0] ?? null;

            return is_array($row) ? $row : null;
        }, 120, 5);
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
