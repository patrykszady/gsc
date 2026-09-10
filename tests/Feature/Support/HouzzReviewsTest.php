<?php

namespace Tests\Feature\Support;

use App\Models\PlatformSetting;
use App\Support\Reviews\HouzzReviews;
use Tests\TestCase;

/** Per-site Houzz review-import settings behind the Platforms card. */
class HouzzReviewsTest extends TestCase
{
    public function test_a_review_url_is_matched_to_the_business_regardless_of_punctuation(): void
    {
        $this->assertTrue(HouzzReviews::reviewUrlBelongsTo('https://www.houzz.com/viewReview/1453810/GS-Construction-review', 'GS Construction'));
        $this->assertTrue(HouzzReviews::reviewUrlBelongsTo('https://www.houzz.com/viewReview/99/J-Peterson-Design-review', 'J. Peterson Design'));
        $this->assertFalse(HouzzReviews::reviewUrlBelongsTo('https://www.houzz.com/viewReview/77/Archadeck-of-Chicagoland-review', 'GS Construction'));
        $this->assertFalse(HouzzReviews::reviewUrlBelongsTo('https://www.houzz.com/viewReview/77/GS-Construction-review', ''));
    }

    public function test_the_import_is_off_until_switched_on_and_the_url_falls_back_to_the_site_config(): void
    {
        config(['socials.houzz.url' => 'https://www.houzz.com/pro/from-config/']);
        // A migration switches the default site on (it imported weekly before the
        // switch existed); every other site starts off. Test the bare default.
        PlatformSetting::put(HouzzReviews::ENABLED_KEY, null);

        $this->assertFalse(HouzzReviews::enabled());
        $this->assertSame('https://www.houzz.com/pro/from-config/', HouzzReviews::profileUrl());

        HouzzReviews::save(' https://www.houzz.com/pro/saved/ ', true);

        $this->assertTrue(HouzzReviews::enabled());
        $this->assertSame('https://www.houzz.com/pro/saved/', HouzzReviews::profileUrl());
        // Same key the Social Media page's profile list edits.
        $this->assertSame('https://www.houzz.com/pro/saved/', PlatformSetting::get('socials.url.houzz'));

        HouzzReviews::save(null, false);

        $this->assertFalse(HouzzReviews::enabled());
        $this->assertSame('https://www.houzz.com/pro/from-config/', HouzzReviews::profileUrl());
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
