<?php

namespace Tests\Feature\Console;

use App\Models\ReviewUrl;
use App\Models\Testimonial;
use App\Support\Reviews\AngiReviews;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * What the command does with a scraped payload — fed from a file so the
 * matching is exercised without a browser or a request to angi.com.
 */
class SyncAngiReviewsMatchingTest extends TestCase
{
    private string $payloadFile;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'socials.angi.url' => 'https://www.angi.com/companylist/us/il/gs.htm',
            'brand.name' => 'GS Construction',
        ]);
        $this->payloadFile = tempnam(sys_get_temp_dir(), 'angi-test-');
    }

    protected function tearDown(): void
    {
        @unlink($this->payloadFile);
        parent::tearDown();
    }

    /** @param  list<array<string, mixed>>  $reviews */
    private function payload(array $reviews, string $businessName = 'GS Construction & Remodeling'): void
    {
        file_put_contents($this->payloadFile, json_encode([
            'source_url' => 'https://www.angi.com/companylist/us/il/gs.htm',
            'business_name' => $businessName,
            'count' => count($reviews),
            'reviews' => $reviews,
        ]));
    }

    private function review(string $name, string $body, ?string $date = '2024-11-20T22:57:16', ?int $rating = 5): array
    {
        return ['reviewer_name' => $name, 'review_description' => $body, 'review_date_raw' => $date, 'star_rating' => $rating];
    }

    private function importFromPayload(array $extra = []): PendingCommand
    {
        return $this->artisan('testimonials:sync-angi-reviews', ['--from-json' => $this->payloadFile] + $extra);
    }

    public function test_it_creates_a_testimonial_per_review_and_links_each_to_the_profile(): void
    {
        $this->payload([
            $this->review('Denise M.', 'They remodelled our kitchen and the workmanship was first-rate.'),
            $this->review('Teresa M.', 'Highly recommend, they kept the project moving.', '2022-12-21T09:04:58.539187'),
        ]);

        $this->importFromPayload()->assertExitCode(0);

        $this->assertSame(2, Testimonial::count());
        $denise = Testimonial::where('reviewer_name', 'Denise M.')->sole();
        $this->assertSame('2024-11-20', $denise->review_date->toDateString());
        $this->assertSame(5, $denise->star_rating);
        $this->assertSame('angi', $denise->reviewUrls->first()->platform);
        $this->assertSame('https://www.angi.com/companylist/us/il/gs.htm', $denise->reviewUrls->first()->url);
        $this->assertSame(2, AngiReviews::lastRun()['created']);
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $this->payload([$this->review('Denise M.', 'They remodelled our kitchen and the workmanship was first-rate.')]);

        $this->importFromPayload()->assertExitCode(0);
        $this->importFromPayload()->assertExitCode(0);

        $this->assertSame(1, Testimonial::count());
        $this->assertSame(0, AngiReviews::lastRun()['created']);
        $this->assertSame(1, AngiReviews::lastRun()['matched']);
    }

    public function test_a_review_already_stored_from_another_site_is_not_duplicated(): void
    {
        // The same review, syndicated to Google, with different punctuation.
        $existing = Testimonial::create([
            'reviewer_name' => 'Denise McMorrow',
            'review_description' => 'They remodelled our kitchen — and the workmanship was first-rate!',
            'review_date' => '2024-11-20',
            'star_rating' => 5,
        ]);
        ReviewUrl::create(['testimonial_id' => $existing->id, 'platform' => 'google', 'url' => 'https://g.page/r/x/review']);

        $this->payload([$this->review('Denise M.', 'They remodelled our kitchen and the workmanship was first-rate!')]);

        $this->importFromPayload()->assertExitCode(0);

        $this->assertSame(1, Testimonial::count(), 'matched on text, so no second copy');
        $this->assertSame('Denise McMorrow', Testimonial::sole()->reviewer_name, 'the stored testimonial is left alone');
    }

    public function test_the_same_reviewer_and_date_is_treated_as_one_review(): void
    {
        Testimonial::create(['reviewer_name' => 'Teresa M.', 'review_description' => 'An older wording of the same review.', 'review_date' => '2022-12-21', 'star_rating' => 5]);

        $this->payload([$this->review('Teresa M.', 'Completely different words here about the remodel.', '2022-12-21T09:04:58')]);

        $this->importFromPayload()->assertExitCode(0);

        $this->assertSame(1, Testimonial::count());
    }

    public function test_it_refuses_a_page_belonging_to_another_business(): void
    {
        $this->payload([$this->review('Someone Else', 'A review of a different contractor entirely.')], 'Atlas GS Construction Partners');

        $this->importFromPayload()->assertExitCode(1);

        $this->assertSame(0, Testimonial::count());
        $this->assertStringContainsString('Atlas GS Construction Partners', AngiReviews::lastRun()['error']);
    }

    public function test_a_review_with_no_body_or_no_name_is_counted_but_not_created(): void
    {
        $this->payload([
            $this->review('', 'A body with nobody attached.'),
            $this->review('No Body', ''),
            $this->review('Real Reviewer', 'A genuine review of the work.'),
        ]);

        $this->importFromPayload()->assertExitCode(0);

        $this->assertSame(1, Testimonial::count());
        $this->assertSame(2, AngiReviews::lastRun()['parse_failures']);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->payload([$this->review('Denise M.', 'They remodelled our kitchen and the workmanship was first-rate.')]);

        $this->importFromPayload(['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, Testimonial::count());
        $this->assertNull(AngiReviews::lastRun());
    }
}
