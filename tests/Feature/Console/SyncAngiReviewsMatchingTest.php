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
        // It was cited on the first run; the second must not add a duplicate row.
        $this->assertSame(0, AngiReviews::lastRun()['linked']);
        $this->assertSame(1, Testimonial::sole()->reviewUrls()->where('platform', 'angi')->count());
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
        $this->assertSame('Denise McMorrow', Testimonial::sole()->reviewer_name, 'the stored wording is left alone');
        // It IS on Angi, so it now cites Angi as well as Google — this is what
        // the Platforms count and the public citations read.
        $this->assertEqualsCanonicalizing(['google', 'angi'], Testimonial::sole()->reviewUrls->pluck('platform')->all());
        $this->assertSame('https://www.angi.com/companylist/us/il/gs.htm', Testimonial::sole()->reviewUrls->firstWhere('platform', 'angi')->url);
        $this->assertSame(1, AngiReviews::lastRun()['linked']);
    }

    /**
     * Angi serves its review bodies HTML-encoded (and double-encoded at
     * that). Stored raw, the site would print "couldn&#39;t" at a reader,
     * and the text would never match the same review held from elsewhere.
     */
    public function test_html_encoded_text_is_decoded_before_it_is_stored(): void
    {
        $this->payload([$this->review('Ron &amp;#39; Denise', 'They said we couldn&amp;#39;t have it both ways &amp;amp; then delivered.')]);

        $this->importFromPayload()->assertExitCode(0);

        $stored = Testimonial::sole();
        $this->assertSame("They said we couldn't have it both ways & then delivered.", $stored->review_description);
        $this->assertSame("Ron ' Denise", $stored->reviewer_name);
    }

    public function test_an_html_encoded_review_still_matches_the_copy_already_stored(): void
    {
        // The same review, held from Google with a real apostrophe.
        $existing = Testimonial::create([
            'reviewer_name' => 'Teresa McMillin',
            'review_description' => "We couldn't be happier with the results. They kept the project moving along at all times.",
            'review_date' => '2022-12-21',
            'star_rating' => 5,
        ]);
        ReviewUrl::create(['testimonial_id' => $existing->id, 'platform' => 'google', 'url' => 'https://g.page/r/x/review']);

        $this->payload([$this->review('Teresa M.', 'We couldn&#39;t be happier with the results. They kept the project moving along at all times.', '2022-12-21T09:04:58')]);

        $this->importFromPayload()->assertExitCode(0);

        $this->assertSame(1, Testimonial::count(), 'the encoded copy is the same review, not a second one');
        $this->assertSame('Teresa McMillin', Testimonial::sole()->reviewer_name);
        $this->assertEqualsCanonicalizing(['google', 'angi'], Testimonial::sole()->reviewUrls->pluck('platform')->all());
    }

    public function test_the_same_reviewer_and_date_is_treated_as_one_review(): void
    {
        Testimonial::create(['reviewer_name' => 'Teresa M.', 'review_description' => 'An older wording of the same review.', 'review_date' => '2022-12-21', 'star_rating' => 5]);

        $this->payload([$this->review('Teresa M.', 'Completely different words here about the remodel.', '2022-12-21T09:04:58')]);

        $this->importFromPayload()->assertExitCode(0);

        $this->assertSame(1, Testimonial::count());
        $this->assertSame(1, Testimonial::sole()->reviewUrls()->where('platform', 'angi')->count());
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
        $existing = Testimonial::create(['reviewer_name' => 'Denise M.', 'review_description' => 'They remodelled our kitchen and the workmanship was first-rate.', 'review_date' => '2024-11-20', 'star_rating' => 5]);

        $this->payload([
            $this->review('Denise M.', 'They remodelled our kitchen and the workmanship was first-rate.'),
            $this->review('Somebody New', 'A review the site has never seen before.'),
        ]);

        $this->importFromPayload(['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(1, Testimonial::count(), 'nothing created');
        $this->assertSame(0, $existing->reviewUrls()->count(), 'nothing cited');
        $this->assertNull(AngiReviews::lastRun());
    }
}
