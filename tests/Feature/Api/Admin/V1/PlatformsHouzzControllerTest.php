<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\RunSeoChannelSyncJob;
use App\Models\PlatformSetting;
use App\Models\ReviewUrl;
use App\Models\Testimonial;
use App\Support\Reviews\HouzzReviews;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * /api/admin/v1/platforms — the Houzz card: status (URL from the Social
 * Media page's profile links, imported count, last run) and import-now.
 * Never reaches houzz.com: the import is a queued job, faked here.
 */
class PlatformsHouzzControllerTest extends TestCase
{
    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    public function test_status_reports_the_houzz_settings_and_imported_review_count(): void
    {
        config(['socials.houzz.url' => 'https://www.houzz.com/pro/from-config/']);

        $houzz = Testimonial::create(['reviewer_name' => 'Kathy McHugh', 'review_description' => 'Wonderful kitchen.', 'review_date' => '2026-05-01', 'star_rating' => 5]);
        ReviewUrl::create(['testimonial_id' => $houzz->id, 'platform' => 'houzz', 'url' => 'https://www.houzz.com/viewReview/1/GS-Construction-review']);
        $google = Testimonial::create(['reviewer_name' => 'Philip Etter', 'review_description' => 'Great bath.', 'review_date' => '2026-06-01', 'star_rating' => 5]);
        ReviewUrl::create(['testimonial_id' => $google->id, 'platform' => 'google', 'url' => 'https://g.page/r/x/review']);

        $data = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk()->json('data.houzz');

        $this->assertSame('https://www.houzz.com/pro/from-config/', $data['profile_url']);
        $this->assertArrayNotHasKey('enabled', $data);
        $this->assertSame(1, $data['reviews_count']);
        $this->assertSame('2026-05-01', $data['latest_review_date']);
        $this->assertNull($data['last_run']);
        $this->assertFalse($data['running']);
    }

    public function test_the_houzz_url_comes_from_the_social_media_pages_profile_links(): void
    {
        config(['socials.houzz.url' => null]);

        // The Social Media page's profile-links form is the only editor.
        $this->putJson('/api/admin/v1/social-media/urls', [
            'urls' => ['houzz' => 'https://www.houzz.com/pro/jpetersondesign/'],
        ], $this->bearer())->assertOk();

        $data = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk()->json('data.houzz');
        $this->assertSame('https://www.houzz.com/pro/jpetersondesign/', $data['profile_url']);

        // There is no separate Houzz settings endpoint to drift from it.
        $this->postJson('/api/admin/v1/platforms/houzz/settings', ['profile_url' => 'https://www.houzz.com/pro/x/'], $this->bearer())
            ->assertNotFound();
    }

    public function test_import_now_queues_the_sync_as_this_site_and_refuses_without_a_url_or_while_running(): void
    {
        Bus::fake();
        config(['socials.houzz.url' => null]);

        $this->postJson('/api/admin/v1/platforms/houzz/reviews/sync', [], $this->bearer())
            ->assertOk()->assertJsonPath('data.ok', false);
        Bus::assertNothingDispatched();

        HouzzReviews::save('https://www.houzz.com/pro/jpetersondesign/');

        $this->postJson('/api/admin/v1/platforms/houzz/reviews/sync', [], $this->bearer())
            ->assertOk()->assertJsonPath('data.ok', true);

        Bus::assertDispatched(RunSeoChannelSyncJob::class, fn (RunSeoChannelSyncJob $job) => $job->command === 'testimonials:sync-houzz-reviews'
            && ($job->options['--only-new'] ?? false) === true
            && $job->siteId !== null);
        $this->assertTrue(HouzzReviews::isRunning());

        // A second click while the first import is still running is a no-op.
        $this->postJson('/api/admin/v1/platforms/houzz/reviews/sync', [], $this->bearer())
            ->assertOk()->assertJsonPath('data.ok', false);
        Bus::assertDispatchedTimes(RunSeoChannelSyncJob::class, 1);
    }
}
