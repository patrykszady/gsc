<?php

namespace Tests\Feature\Support;

use App\Jobs\RunSeoChannelSyncJob;
use App\Models\PlatformSetting;
use App\Models\Site;
use App\Support\Tenancy;
use App\Support\Reviews\HouzzReviews;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/** Per-site Houzz review-import settings behind the Platforms card. */
class HouzzReviewsTest extends TestCase
{
    public function test_a_review_url_is_matched_to_the_business_regardless_of_punctuation(): void
    {
        $this->assertTrue(HouzzReviews::reviewUrlBelongsTo('https://www.houzz.com/viewReview/1453810/GS-Construction-review', 'GS Construction'));
        $this->assertTrue(HouzzReviews::reviewUrlBelongsTo('https://www.houzz.com/viewReview/99/J-Peterson-Design-LLC-review', 'J. Peterson Design'));
        $this->assertFalse(HouzzReviews::reviewUrlBelongsTo('https://www.houzz.com/viewReview/77/Archadeck-of-Chicagoland-review', 'GS Construction'));
        $this->assertFalse(HouzzReviews::reviewUrlBelongsTo('https://www.houzz.com/viewReview/77/GS-Construction-review', ''));
        // The brand in a profile link is not a review.
        $this->assertFalse(HouzzReviews::reviewUrlBelongsTo('https://www.houzz.com/professionals/kitchen-and-bath-remodelers/gs-construction-pfvwus-pf~1', 'GS Construction'));
        // Another business that merely contains the name is not ours.
        $this->assertFalse(HouzzReviews::reviewUrlBelongsTo('https://www.houzz.com/viewReview/55/Atlas-GS-Construction-Partners-review', 'GS Construction'));
        $this->assertFalse(HouzzReviews::reviewUrlBelongsTo('https://www.houzz.com/viewReview/5/New-GS-Construction-Group-review', 'GS Construction'));
        $this->assertTrue(HouzzReviews::reviewUrlBelongsTo('https://www.houzz.com/viewReview/5/GS-Construction-review?utm=x#top', 'GS Construction'));
    }

    public function test_the_url_is_the_social_media_pages_houzz_link_and_falls_back_to_the_site_config(): void
    {
        config(['socials.houzz.url' => 'https://www.houzz.com/pro/from-config/']);

        $this->assertSame('https://www.houzz.com/pro/from-config/', HouzzReviews::profileUrl());
        $this->assertTrue(HouzzReviews::automated());

        HouzzReviews::save(' https://www.houzz.com/pro/saved/ ');

        $this->assertSame('https://www.houzz.com/pro/saved/', HouzzReviews::profileUrl());
        // Same key the Social Media page's profile list edits.
        $this->assertSame('https://www.houzz.com/pro/saved/', PlatformSetting::get('socials.url.houzz'));

        HouzzReviews::save(null);

        $this->assertSame('https://www.houzz.com/pro/from-config/', HouzzReviews::profileUrl());
    }

    public function test_a_site_without_its_own_socials_file_never_inherits_the_default_sites_houzz_profile(): void
    {
        config(['socials.houzz.url' => 'https://www.houzz.com/pro/gs-from-shared-config/']);
        $other = Site::forceCreate(['slug' => 'acme', 'name' => 'Acme', 'theme' => 'gsc', 'hosts' => ['acme.test'], 'primary_host' => 'acme.test', 'is_active' => true]);

        Tenancy::for($other, function () {
            $this->assertNull(HouzzReviews::profileUrl());
            $this->assertFalse(HouzzReviews::automated());

            HouzzReviews::save('https://www.houzz.com/pro/acme/');
            $this->assertSame('https://www.houzz.com/pro/acme/', HouzzReviews::profileUrl());
        });

        // Its own stored URL stayed with it; the default site is untouched.
        $this->assertSame('https://www.houzz.com/pro/gs-from-shared-config/', HouzzReviews::profileUrl());

        // A site that sets its own Houzz page in its settings owns it.
        $owner = Site::forceCreate(['slug' => 'owner', 'name' => 'Owner', 'theme' => 'gsc', 'hosts' => ['owner.test'], 'primary_host' => 'owner.test', 'is_active' => true,
            'settings' => ['config' => ['socials' => ['houzz' => ['url' => 'https://www.houzz.com/pro/owner-from-settings/']]]]]);
        Tenancy::for($owner, fn () => $this->assertSame('https://www.houzz.com/pro/owner-from-settings/', HouzzReviews::profileUrl()));
    }

    public function test_the_weekly_schedule_queues_one_import_per_active_site_with_a_url(): void
    {
        Bus::fake();
        config(['socials.houzz.url' => 'https://www.houzz.com/pro/gs/']);

        $withUrl = Site::forceCreate(['slug' => 'acme', 'name' => 'Acme', 'theme' => 'gsc', 'hosts' => ['acme.test'], 'primary_host' => 'acme.test', 'is_active' => true]);
        Tenancy::for($withUrl, fn () => HouzzReviews::save('https://www.houzz.com/pro/acme/'));
        $dormant = Site::forceCreate(['slug' => 'dormant', 'name' => 'Dormant', 'theme' => 'gsc', 'hosts' => ['dormant.test'], 'primary_host' => 'dormant.test', 'is_active' => false]);
        Tenancy::for($dormant, fn () => HouzzReviews::save('https://www.houzz.com/pro/dormant/'));
        $bare = Site::forceCreate(['slug' => 'bare', 'name' => 'Bare', 'theme' => 'gsc', 'hosts' => ['bare.test'], 'primary_host' => 'bare.test', 'is_active' => true]);

        $dispatched = HouzzReviews::dispatchScheduledImports();

        $this->assertContains('acme', $dispatched);
        $this->assertNotContains('dormant', $dispatched, 'inactive sites wait for launch');
        $this->assertNotContains('bare', $dispatched, 'no URL, no import — and no inherited GS URL');
        Bus::assertDispatched(RunSeoChannelSyncJob::class, fn (RunSeoChannelSyncJob $job) => $job->siteId === $withUrl->id
            && $job->command === 'testimonials:sync-houzz-reviews'
            && ($job->options['--only-new'] ?? false) === true);
        Bus::assertNotDispatched(RunSeoChannelSyncJob::class, fn (RunSeoChannelSyncJob $job) => in_array($job->siteId, [$dormant->id, $bare->id], true));
        Tenancy::for($withUrl, fn () => $this->assertTrue(HouzzReviews::isRunning()));
    }

    public function test_a_run_record_replaces_the_running_flag(): void
    {
        $this->assertNull(HouzzReviews::lastRun());

        HouzzReviews::markRunning(true);
        $this->assertTrue(HouzzReviews::isRunning());

        HouzzReviews::recordRun(['scraped' => 12, 'created' => 2]);

        $this->assertFalse(HouzzReviews::isRunning());
        $run = HouzzReviews::lastRun();
        $this->assertSame(12, $run['scraped']);
        $this->assertSame(2, $run['created']);
        $this->assertNull($run['error']);
        $this->assertNotEmpty($run['at']);

        HouzzReviews::recordRun([], 'Houzz blocked the request.');
        $this->assertSame('Houzz blocked the request.', HouzzReviews::lastRun()['error']);
    }
}
