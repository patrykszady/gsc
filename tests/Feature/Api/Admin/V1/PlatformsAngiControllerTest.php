<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\RunSeoChannelSyncJob;
use App\Models\ReviewUrl;
use App\Models\Testimonial;
use App\Support\Reviews\AngiReviews;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * /api/admin/v1/platforms — the Angi card: status (URL from the Social
 * Media page's profile links, imported count, last run) and import-now.
 * Never reaches angi.com: the import is a queued job, faked here.
 */
class PlatformsAngiControllerTest extends TestCase
{
    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    public function test_status_reports_the_angi_profile_and_imported_reviews(): void
    {
        config(['socials.angi.url' => 'https://www.angi.com/companylist/us/il/gs.htm']);

        $angi = Testimonial::create(['reviewer_name' => 'Teresa M.', 'review_description' => 'Great remodel.', 'review_date' => '2022-12-21', 'star_rating' => 5]);
        ReviewUrl::create(['testimonial_id' => $angi->id, 'platform' => 'angi', 'url' => 'https://www.angi.com/companylist/us/il/gs.htm']);

        $data = $this->getJson('/api/admin/v1/platforms/status', $this->bearer())->assertOk()->json('data');

        $this->assertSame('https://www.angi.com/companylist/us/il/gs.htm', $data['angi']['profile_url']);
        $this->assertSame(1, $data['angi']['reviews_count']);
        $this->assertSame('2022-12-21', $data['angi']['latest_review_date']);
        $this->assertFalse($data['angi']['running']);
        // Houzz keeps its own block; the two never share a count.
        $this->assertSame(0, $data['houzz']['reviews_count']);
    }

    public function test_import_now_queues_the_angi_sync_and_refuses_without_a_url_or_while_running(): void
    {
        Bus::fake();
        config(['socials.angi.url' => null]);

        $this->postJson('/api/admin/v1/platforms/angi/reviews/sync', [], $this->bearer())
            ->assertOk()->assertJsonPath('data.ok', false);
        Bus::assertNothingDispatched();

        AngiReviews::save('https://www.angi.com/companylist/us/il/gs.htm');

        $this->postJson('/api/admin/v1/platforms/angi/reviews/sync', [], $this->bearer())
            ->assertOk()->assertJsonPath('data.ok', true);

        Bus::assertDispatched(RunSeoChannelSyncJob::class, fn (RunSeoChannelSyncJob $job) => $job->command === 'testimonials:sync-angi-reviews'
            && $job->siteId !== null);
        $this->assertTrue(AngiReviews::isRunning());

        // A second click while the first import is still running is a no-op.
        $this->postJson('/api/admin/v1/platforms/angi/reviews/sync', [], $this->bearer())
            ->assertOk()->assertJsonPath('data.ok', false);
        Bus::assertDispatchedTimes(RunSeoChannelSyncJob::class, 1);
    }

    public function test_only_the_scraped_review_platforms_have_an_import_endpoint(): void
    {
        Bus::fake();

        $this->postJson('/api/admin/v1/platforms/yelp/reviews/sync', [], $this->bearer())->assertNotFound();
        $this->postJson('/api/admin/v1/platforms/google/reviews/sync', [], $this->bearer())->assertNotFound();
        Bus::assertNothingDispatched();
    }
}
