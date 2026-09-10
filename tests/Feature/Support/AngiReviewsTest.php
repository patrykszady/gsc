<?php

namespace Tests\Feature\Support;

use App\Jobs\RunSeoChannelSyncJob;
use App\Models\ReviewUrl;
use App\Models\Site;
use App\Models\Testimonial;
use App\Support\Reviews\AngiReviews;
use App\Support\Reviews\HouzzReviews;
use App\Support\Reviews\ReviewImport;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/** Per-site Angi review import behind the Platforms card. */
class AngiReviewsTest extends TestCase
{
    public function test_the_url_is_the_social_media_pages_angi_link(): void
    {
        config(['socials.angi.url' => 'https://www.angi.com/companylist/us/il/chicagoland/gs-construction-and-remodeling-reviews-11400361.htm']);

        $this->assertSame('https://www.angi.com/companylist/us/il/chicagoland/gs-construction-and-remodeling-reviews-11400361.htm', AngiReviews::profileUrl());
        $this->assertTrue(AngiReviews::automated());

        AngiReviews::save(' https://www.angi.com/companylist/us/il/saved.htm ');

        $this->assertSame('https://www.angi.com/companylist/us/il/saved.htm', AngiReviews::profileUrl());
        // The same key the Social Media page's profile list edits.
        $this->assertSame('https://www.angi.com/companylist/us/il/saved.htm', \App\Models\PlatformSetting::get('socials.url.angi'));
    }

    public function test_a_site_without_its_own_socials_file_never_inherits_the_default_sites_angi_profile(): void
    {
        config(['socials.angi.url' => 'https://www.angi.com/companylist/us/il/gs.htm']);
        $other = Site::forceCreate(['slug' => 'acme', 'name' => 'Acme', 'theme' => 'gsc', 'hosts' => ['acme.test'], 'primary_host' => 'acme.test', 'is_active' => true]);

        Tenancy::for($other, function () {
            $this->assertNull(AngiReviews::profileUrl());
            $this->assertFalse(AngiReviews::automated());
        });
    }

    /**
     * The one guard that matters when somebody pastes a URL: Angi appends the
     * registered suffix to the name, but another contractor's page must not
     * import as ours.
     */
    public function test_the_page_business_name_must_start_with_the_brand(): void
    {
        $this->assertTrue(AngiReviews::pageBelongsToBrand('GS Construction & Remodeling', 'GS Construction'));
        $this->assertTrue(AngiReviews::pageBelongsToBrand('J. Peterson Design LLC', 'J. Peterson Design'));
        $this->assertFalse(AngiReviews::pageBelongsToBrand('Atlas GS Construction Partners', 'GS Construction'));
        $this->assertFalse(AngiReviews::pageBelongsToBrand('Some Other Remodeler', 'GS Construction'));
        $this->assertFalse(AngiReviews::pageBelongsToBrand('GS Construction & Remodeling', ''));
    }

    public function test_status_counts_only_angi_reviews_and_reports_the_newest(): void
    {
        $angi = Testimonial::create(['reviewer_name' => 'Teresa M.', 'review_description' => 'Great remodel.', 'review_date' => '2022-12-21', 'star_rating' => 5]);
        ReviewUrl::create(['testimonial_id' => $angi->id, 'platform' => 'angi', 'url' => 'https://www.angi.com/companylist/us/il/gs.htm']);
        $houzz = Testimonial::create(['reviewer_name' => 'Denise M.', 'review_description' => 'Lovely kitchen.', 'review_date' => '2024-11-20', 'star_rating' => 5]);
        ReviewUrl::create(['testimonial_id' => $houzz->id, 'platform' => 'houzz', 'url' => 'https://www.houzz.com/viewReview/1/GS-Construction-review']);

        $status = AngiReviews::status();

        $this->assertSame(1, $status['reviews_count']);
        $this->assertSame('2022-12-21', $status['latest_review_date']);
        $this->assertFalse($status['running']);
        $this->assertNull($status['last_run']);
    }

    public function test_a_run_record_replaces_the_running_flag_per_platform(): void
    {
        AngiReviews::markRunning(true);

        $this->assertTrue(AngiReviews::isRunning());
        // Each platform's flag is its own — a running Angi import must not
        // make the Houzz card look busy.
        $this->assertFalse(HouzzReviews::isRunning());

        AngiReviews::recordRun(['scraped' => 3, 'created' => 1]);

        $this->assertFalse(AngiReviews::isRunning());
        $this->assertSame(1, AngiReviews::lastRun()['created']);
        $this->assertNull(AngiReviews::lastRun()['error']);
        $this->assertNull(HouzzReviews::lastRun(), 'the Houzz card keeps its own last run');
    }

    public function test_the_weekly_schedule_queues_each_platform_for_active_sites_with_that_url(): void
    {
        Bus::fake();
        config(['socials.angi.url' => 'https://www.angi.com/companylist/us/il/gs.htm', 'socials.houzz.url' => null]);

        $dispatched = [];
        foreach (ReviewImport::sources() as $source) {
            $dispatched[$source::platform()] = $source::dispatchScheduledImports();
        }

        $this->assertSame(['gsc'], $dispatched['angi']);
        $this->assertSame([], $dispatched['houzz'], 'no Houzz URL, no Houzz import');
        Bus::assertDispatched(RunSeoChannelSyncJob::class, fn (RunSeoChannelSyncJob $job) => $job->command === 'testimonials:sync-angi-reviews'
            && ($job->options['--only-new'] ?? false) === true
            && $job->siteId === Site::current()->id);
        Bus::assertDispatchedTimes(RunSeoChannelSyncJob::class, 1);
    }
}
