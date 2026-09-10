<?php

namespace Tests\Feature\Console;

use App\Support\Reviews\HouzzReviews;
use Tests\TestCase;

/** The command takes its profile URL from the site's Platforms setting; without one it stops before touching Houzz. */
class SyncHouzzReviewsCommandTest extends TestCase
{
    public function test_it_stops_and_records_the_problem_when_the_site_has_no_houzz_url(): void
    {
        config(['socials.houzz.url' => null]);

        $this->artisan('testimonials:sync-houzz-reviews --browser-scrape --only-new')
            ->expectsOutputToContain('No Houzz profile URL is configured')
            ->assertExitCode(1);

        $this->assertSame('No Houzz profile URL configured.', HouzzReviews::lastRun()['error']);
    }

    public function test_a_dry_run_without_a_url_leaves_no_run_record(): void
    {
        config(['socials.houzz.url' => null]);

        $this->artisan('testimonials:sync-houzz-reviews --dry-run')->assertExitCode(1);

        $this->assertNull(HouzzReviews::lastRun());
    }
}
