<?php

namespace Tests\Feature\SeoIntel;

use App\Services\Seo\Intel\Sources\BusinessDataSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessDataSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** Review counts for our own listing / profile, mutated between the two runs. */
    public static int $ourVotes = 40;

    public static float $ourRating = 4.9;

    /** Prism's review count, mutated between runs to exercise competitor_review_gain. */
    public static int $prismVotes = 60;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://gs.construction',
            'services.dataforseo.login' => 'u',
            'services.dataforseo.password' => 'p',
            'brand.name' => 'GS Construction',
            'seo-intel.sources' => [BusinessDataSource::class],
            'seo.map_pack.center_lat' => 42.102847,
            'seo.map_pack.center_lng' => -87.9275628,
            // Force the domain fallback for a deterministic subject regardless of the local .env's real place id.
            'services.google.business_profile.place_id' => '',
        ]);
        self::$ourVotes = 40;
        self::$ourRating = 4.9;
        self::$prismVotes = 60;

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'appendix/user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 50]]]]]]);
            }

            if (str_contains($url, 'my_business_info/live')) {
                return Http::response(['tasks' => [['cost' => 0.0054, 'status_code' => 20000, 'result' => [[
                    'items' => [[
                        'title' => 'GS Construction & Remodeling',
                        'category' => 'Kitchen remodeler',
                        'additional_categories' => ['Bathroom remodeler'],
                        'rating' => ['value' => self::$ourRating, 'votes_count' => self::$ourVotes],
                        'is_claimed' => true,
                        'total_photos' => 42,
                        'description' => 'Family-owned remodeling contractor.',
                        'place_id' => 'ChIJ-our-place',
                        'cid' => '12345',
                        'attributes' => [
                            'available_attributes' => ['from_the_business' => ['is_small_business'], 'planning' => ['requires_appointments']],
                            'unavailable_attributes' => null,
                        ],
                        'work_time' => ['work_hours' => ['current_status' => 'open']],
                        'price_level' => null,
                        'url' => 'https://gs.construction/',
                        'domain' => 'gs.construction',
                    ]],
                ]]]]]);
            }

            if (str_contains($url, 'business_listings/search/live')) {
                $listing = fn (string $pid, string $title, string $domain, float $rating, int $votes, array $attrs = []) => [
                    'title' => $title, 'category' => 'Kitchen remodeler', 'additional_categories' => [],
                    'rating' => ['value' => $rating, 'votes_count' => $votes], 'is_claimed' => true,
                    'attributes' => ['available_attributes' => $attrs, 'unavailable_attributes' => null],
                    'work_time' => ['work_hours' => ['current_status' => 'open']],
                    'phone' => '+13125551234', 'total_photos' => 10,
                    'url' => "https://{$domain}/", 'domain' => $domain, 'cid' => $pid, 'place_id' => $pid,
                ];
                $items = [
                    $listing('p-prism', 'Prism Kitchen & Bath', 'prismkb.test', 4.8, self::$prismVotes, ['service_options' => ['offers_online_appointments'], 'accessibility' => ['has_wheelchair_accessible_entrance']]),
                    $listing('p-dream', 'Dreamline Remodeling', 'dreamline.test', 4.6, 55, ['service_options' => ['offers_online_appointments']]),
                    $listing('p-yello', 'YelloSquare', 'yellosquare.test', 4.5, 30, ['accessibility' => ['has_wheelchair_accessible_entrance']]),
                    $listing('p-ace', 'Ace Remodel', 'acermdl.test', 4.4, 20),
                    $listing('p-bath', 'Bath Co', 'bathco.test', 4.3, 15),
                    $listing('ChIJ-our-place', 'GS Construction & Remodeling', 'gs.construction', self::$ourRating, self::$ourVotes),
                    // A retailer that lists "Kitchen remodeler" as a side category must not become a competitor.
                    array_merge($listing('p-ikea', 'IKEA', 'ikea.test', 4.4, 14945), ['category' => 'Furniture store', 'additional_categories' => ['Kitchen remodeler', 'Appliance store']]),
                    array_merge($listing('p-plumb', 'Reliance Plumbing', 'reliance.test', 4.7, 826), ['category' => 'Plumber', 'additional_categories' => ['Bathroom remodeler']]),
                ];
                // Keep the fixture already sorted by votes desc, as the real order_by would return.
                usort($items, fn ($a, $b) => $b['rating']['votes_count'] <=> $a['rating']['votes_count']);

                return Http::response(['tasks' => [['cost' => 0.048, 'status_code' => 20000, 'result' => [['items' => $items]]]]]);
            }

            if (str_contains($url, 'reviews/task_post')) {
                return Http::response(['tasks' => [['id' => 'task-1', 'status_code' => 20100, 'status_message' => 'Task Created', 'cost' => 0.0075]]]);
            }

            if (str_contains($url, 'reviews/task_get')) {
                $now = Carbon::now();
                $items = [
                    ['profile_name' => 'Alice H', 'rating' => ['value' => 5], 'review_text' => 'Wonderful team, great kitchen.', 'timestamp' => $now->copy()->subDays(3)->format('Y-m-d H:i:s') . ' +00:00', 'owner_answer' => 'Thank you!'],
                    ['profile_name' => 'Bob R', 'rating' => ['value' => 1.0], 'review_text' => str_repeat('Terrible experience, would not recommend at all. ', 3), 'timestamp' => $now->copy()->subDays(5)->format('Y-m-d H:i:s') . ' +00:00', 'owner_answer' => null],
                    ['profile_name' => 'Carla T', 'rating' => ['value' => 5], 'review_text' => 'Solid work.', 'timestamp' => $now->copy()->subDays(10)->format('Y-m-d H:i:s') . ' +00:00', 'owner_answer' => null],
                ];

                return Http::response(['tasks' => [['status_code' => 20000, 'result' => [['reviews_count' => count($items), 'items' => $items]]]]]);
            }

            return Http::response(['tasks' => [['cost' => 0, 'status_code' => 20000, 'result' => []]]]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_first_run_stores_snapshots_for_profile_listings_and_reviews(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['business_data'], '--budget' => 1])->assertExitCode(0);

        $this->assertSame(0, DB::table('seo_intel_runs')->where('error', '!=', '')->count());

        $profile = DB::table('seo_intel_snapshots')->where('family', 'business_data')->where('kind', 'profile')->first();
        $this->assertNotNull($profile);
        $this->assertSame('gs.construction', $profile->subject);
        $metrics = json_decode((string) $profile->metrics, true);
        $this->assertEquals(4.9, $metrics['rating']);
        $this->assertSame(40, $metrics['votes']);
        $this->assertSame(1, $metrics['is_claimed']);

        $listings = DB::table('seo_intel_snapshots')->where('family', 'business_data')->where('kind', 'listing')->get();
        $this->assertSame(6, $listings->count(), 'our own listing plus 5 competitors; the furniture store and the plumber are not remodelers');
        $this->assertNull($listings->firstWhere('subject', 'p-ikea'));

        $reviews = DB::table('seo_intel_snapshots')->where('family', 'business_data')->where('kind', 'reviews')->first();
        $this->assertNotNull($reviews);
        $rmetrics = json_decode((string) $reviews->metrics, true);
        $this->assertSame(3, $rmetrics['reviews_total']);
        $this->assertSame(3, $rmetrics['last_30_days']);
        $this->assertSame(2, $rmetrics['unanswered'], 'Bob and Carla have no owner_answer');

        // No findings can compare yet (nothing to diff for rating/competitor gain), but
        // reviews-derived findings (unanswered, low rating) already stand on the first run.
        $codes = DB::table('seo_intel_findings')->pluck('code')->all();
        $this->assertContains('business_data.unanswered_reviews', $codes);
        $this->assertContains('business_data.low_rating_recent', $codes);
        $this->assertContains('business_data.review_rank', $codes);
    }

    public function test_second_run_opens_rating_drop_and_competitor_gain_findings(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['business_data'], '--budget' => 1])->assertExitCode(0);

        Carbon::setTestNow('2026-09-12 06:00:00');
        self::$ourRating = 4.7; // drop of 0.2
        self::$prismVotes = 75; // +15 reviews
        $this->artisan('seo:intel', ['family' => ['business_data'], '--budget' => 1])->assertExitCode(0);

        $findings = DB::table('seo_intel_findings')->whereNull('resolved_at')->get()->keyBy('code');

        $this->assertArrayHasKey('business_data.rating_drop', $findings->toArray());
        $ratingDrop = $findings['business_data.rating_drop'];
        $this->assertSame('critical', $ratingDrop->severity);
        $delta = json_decode((string) $ratingDrop->delta, true);
        $this->assertEquals(4.9, $delta['rating']['prev']);
        $this->assertEquals(4.7, $delta['rating']['now']);

        $this->assertArrayHasKey('business_data.competitor_review_gain', $findings->toArray());
        $gain = $findings['business_data.competitor_review_gain'];
        $this->assertStringContainsString('Prism', $gain->title);
        $gainDelta = json_decode((string) $gain->delta, true);
        $this->assertSame(60, $gainDelta['votes']['prev']);
        $this->assertSame(75, $gainDelta['votes']['now']);

        // Our own listing/profile is never reported as a "competitor".
        foreach ($findings as $f) {
            if ($f->code === 'business_data.competitor_review_gain' || $f->code === 'business_data.new_top10_listing') {
                $this->assertNotSame('gs.construction', $f->subject);
                $this->assertNotSame('ChIJ-our-place', $f->subject);
            }
        }

        $rank = $findings['business_data.review_rank'];
        // Prism 75, Dreamline 55, YelloSquare 30, us 40 -> we rank 3rd.
        $this->assertStringContainsString('#3', $rank->title);
    }

    public function test_report_has_the_promised_tiles_and_tables(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['business_data'], '--budget' => 1])->assertExitCode(0);

        $source = app(BusinessDataSource::class);
        $report = $source->report();

        $labels = collect($report['tiles'])->pluck('value', 'label');
        $this->assertEquals(4.9, $labels['GBP rating']);
        $this->assertSame(40, $labels['GBP reviews']);
        $this->assertSame(3, $labels['Reviews (30d)']);
        $this->assertSame(2, $labels['Unanswered reviews']);

        $listingTable = collect($report['tables'])->firstWhere('title', 'Nearby listings by review count');
        $this->assertNotNull($listingTable);
        $this->assertSame(['Business', 'Rating', 'Reviews', 'Claimed'], $listingTable['columns']);
        $this->assertSame('Prism Kitchen & Bath', $listingTable['rows'][0][0]);
        $this->assertNotEmpty($report['note']);
    }

    public function test_report_is_safe_when_nothing_has_been_collected_yet(): void
    {
        $source = app(BusinessDataSource::class);
        $report = $source->report();
        $this->assertArrayHasKey('tiles', $report);
        $this->assertArrayHasKey('tables', $report);
        $this->assertArrayHasKey('note', $report);
    }

    public function test_profile_and_reviews_calls_use_a_location_coordinate_radius_within_the_documented_minimum(): void
    {
        // my_business_info/live and reviews/task_post document a location_coordinate
        // radius range of 199.9-199999 -- a different, much finer-grained field than
        // business_listings/search/live's kilometre radius. The default must not send
        // a value below that documented minimum (it would previously send "10").
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['business_data'], '--budget' => 1])->assertExitCode(0);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'my_business_info/live')) {
                return true;
            }
            [$lat, $lng, $radius] = explode(',', $request->data()[0]['location_coordinate']);
            $this->assertGreaterThanOrEqual(199.9, (float) $radius);
            $this->assertLessThanOrEqual(199999, (float) $radius);

            return true;
        });

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'reviews/task_post')) {
                return true;
            }
            [$lat, $lng, $radius] = explode(',', $request->data()[0]['location_coordinate']);
            $this->assertGreaterThanOrEqual(199.9, (float) $radius);

            return true;
        });
    }

    public function test_default_keyword_uses_the_configured_brand_city_and_state_not_a_hardcoded_town(): void
    {
        // config/brand.php (and its per-site overrides) is the source of truth for a
        // tenant's home city -- the default keyword must follow it rather than always
        // querying "Prospect Heights, IL" regardless of which tenant is running.
        config(['brand.city' => 'Chicago', 'brand.state' => 'IL']);
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['business_data'], '--budget' => 1])->assertExitCode(0);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'my_business_info/live')) {
                return true;
            }
            $this->assertSame('GS Construction Chicago, IL', $request->data()[0]['keyword']);
            $this->assertStringNotContainsString('Prospect Heights', $request->data()[0]['keyword']);

            return true;
        });
    }

    public function test_cost_cap_stops_the_run_before_the_reviews_call(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        // Profile ($0.0054) and listings (~$0.048) both start under the cap and run;
        // the guard re-checks before the reviews call and skips it once spent exceeds the cap.
        config(['seo-intel.families.business_data.max_cost' => 0.01]);
        $this->artisan('seo:intel', ['family' => ['business_data'], '--budget' => 1])->assertExitCode(0);

        $this->assertSame(1, DB::table('seo_intel_snapshots')->where('family', 'business_data')->where('kind', 'profile')->count());
        $this->assertSame(6, DB::table('seo_intel_snapshots')->where('family', 'business_data')->where('kind', 'listing')->count());
        $this->assertSame(0, DB::table('seo_intel_snapshots')->where('family', 'business_data')->where('kind', 'reviews')->count(), 'reviews call is skipped once the cap is exceeded');
        $run = DB::table('seo_intel_runs')->first();
        $this->assertGreaterThan(0.01, (float) $run->cost);
        $this->assertLessThan(0.07, (float) $run->cost);
    }
}
