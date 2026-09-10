<?php

namespace Tests\Feature\Console;

use App\Support\Reviews\AngiReviews;
use Tests\TestCase;

/**
 * The command takes its profile URL from the site's Social Media setting;
 * without one it stops before launching a browser.
 */
class SyncAngiReviewsCommandTest extends TestCase
{
    public function test_it_stops_and_records_the_problem_when_the_site_has_no_angi_url(): void
    {
        config(['socials.angi.url' => null]);

        $this->artisan('testimonials:sync-angi-reviews')
            ->expectsOutputToContain('No Angi profile URL is configured')
            ->assertExitCode(1);

        $this->assertSame('No Angi profile URL configured.', AngiReviews::lastRun()['error']);
    }

    public function test_a_dry_run_without_a_url_leaves_no_run_record(): void
    {
        config(['socials.angi.url' => null]);

        $this->artisan('testimonials:sync-angi-reviews --dry-run')->assertExitCode(1);

        $this->assertNull(AngiReviews::lastRun());
    }
}
