<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\PublishToSocialMediaJob;
use App\Models\ClientError;
use App\Models\ImageSocialPost;
use App\Models\LandingPage;
use App\Models\PlatformSetting;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\TrackedEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * /api/admin/v1/{landing-pages,social-media,analytics,js-errors} — the
 * ss-systems central admin's Landing Pages, Social Media, Analytics and JS
 * Errors screens.
 *
 * SocialMediaController::post() dispatches a real Meta/GBP publish job on a
 * real connected account — this is a real dev box that may carry real
 * platform credentials (see PlatformsControllerTest), so every test that
 * touches it uses Queue::fake() and never lets a job actually run.
 */
class ContentOpsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    protected function publishedProject(string $type = 'kitchen'): Project
    {
        return Project::create([
            'title' => 'Test Kitchen',
            'slug' => 'test-kitchen-'.uniqid(),
            'project_type' => $type,
            'is_published' => true,
            // LandingPageContentGenerator's proof query is a NOT LIKE
            // against location, which SQL evaluates to NULL (never true)
            // when the column is NULL — a location-less project would
            // silently fail the proof gate rather than count as proof.
            'location' => 'Elsewhere, IL',
        ]);
    }

    protected function eligibleImage(Project $project): ProjectImage
    {
        return ProjectImage::create([
            'project_id' => $project->id,
            'filename' => 'photo.jpg',
            'original_filename' => 'photo.jpg',
            'path' => 'projects/1/photo.jpg',
            'alt_text' => 'A lovely kitchen',
        ]);
    }

    // -- Landing pages -----------------------------------------------------

    public function test_landing_pages_index_is_empty_by_default(): void
    {
        $this->getJson('/api/admin/v1/landing-pages', $this->adminApiHeaders())
            ->assertOk()
            ->assertJson(['data' => [], 'meta' => ['total' => 0]]);
    }

    public function test_landing_pages_services_lists_the_fixed_catalogue(): void
    {
        $this->getJson('/api/admin/v1/landing-pages/services', $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.kitchen-remodeling', 'Kitchen Remodeling');
    }

    public function test_generate_creates_a_draft_page_when_proof_exists(): void
    {
        $this->eligibleImage($this->publishedProject('kitchen'));

        $response = $this->postJson('/api/admin/v1/landing-pages', [
            'service' => 'kitchen-remodeling',
            'city' => 'Testville',
        ], $this->adminApiHeaders())->assertCreated();

        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.has_proof', true);
        $this->assertSame(1, LandingPage::count());
    }

    public function test_generate_rejects_a_duplicate_slug_with_a_field_error(): void
    {
        $this->eligibleImage($this->publishedProject('kitchen'));

        $payload = ['service' => 'kitchen-remodeling', 'city' => 'Testville'];
        $this->postJson('/api/admin/v1/landing-pages', $payload, $this->adminApiHeaders())->assertCreated();

        $this->postJson('/api/admin/v1/landing-pages', $payload, $this->adminApiHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['city']]);
    }

    public function test_generate_rejects_a_service_with_no_project_proof(): void
    {
        // No projects at all — every SERVICE_PROJECT_TYPE lookup misses.
        $this->postJson('/api/admin/v1/landing-pages', [
            'service' => 'kitchen-remodeling',
            'city' => 'Nowhereville',
        ], $this->adminApiHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['service']]);
    }

    public function test_publish_is_blocked_without_proof_and_succeeds_with_it(): void
    {
        $page = LandingPage::create([
            'slug' => 'no-proof-page',
            'title' => 'No Proof',
            'h1' => 'No Proof',
            'status' => LandingPage::STATUS_DRAFT,
            'source' => 'manual',
            'proof_project_ids' => [],
        ]);

        $this->patchJson("/api/admin/v1/landing-pages/{$page->id}/publish", [], $this->adminApiHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['page']]);

        $project = $this->publishedProject('kitchen');
        $page->update(['proof_project_ids' => [$project->id]]);

        $this->patchJson("/api/admin/v1/landing-pages/{$page->id}/publish", [], $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->patchJson("/api/admin/v1/landing-pages/{$page->id}/unpublish", [], $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_delete_removes_the_page(): void
    {
        $page = LandingPage::create([
            'slug' => 'to-delete',
            'title' => 'To Delete',
            'h1' => 'To Delete',
            'status' => LandingPage::STATUS_DRAFT,
            'source' => 'manual',
        ]);

        $this->deleteJson("/api/admin/v1/landing-pages/{$page->id}", [], $this->adminApiHeaders())
            ->assertNoContent();

        $this->assertSame(0, LandingPage::count());
    }

    // -- Analytics -----------------------------------------------------------

    public function test_analytics_events_and_summary_reflect_seeded_events(): void
    {
        TrackedEvent::create(['type' => TrackedEvent::TYPE_PHONE_CLICK, 'label' => '555-1212', 'page_path' => '/contact']);
        TrackedEvent::create(['type' => TrackedEvent::TYPE_CTA_CLICK, 'page_path' => '/']);

        $this->getJson('/api/admin/v1/analytics/events?date_filter=all', $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $summary = $this->getJson('/api/admin/v1/analytics/summary?date_filter=all&trend_days=7', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $summary['stats']['phone']);
        $this->assertSame(1, $summary['stats']['cta']);
        $this->assertSame(2, $summary['stats']['total']);
        $this->assertCount(7, $summary['trend']);
    }

    public function test_analytics_type_filter_narrows_both_endpoints(): void
    {
        TrackedEvent::create(['type' => TrackedEvent::TYPE_PHONE_CLICK]);
        TrackedEvent::create(['type' => TrackedEvent::TYPE_CTA_CLICK]);

        $this->getJson('/api/admin/v1/analytics/events?date_filter=all&type_filter=phone_click', $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'phone_click');
    }

    // -- JS errors -------------------------------------------------------

    public function test_js_errors_index_and_summary_respect_status_filter(): void
    {
        ClientError::create([
            'fingerprint' => 'fp-open', 'kind' => 'error', 'message' => 'Boom',
            'occurrences' => 3, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        ClientError::create([
            'fingerprint' => 'fp-resolved', 'kind' => 'promise', 'message' => 'Rejected',
            'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(), 'resolved_at' => now(),
        ]);

        $this->getJson('/api/admin/v1/js-errors?status=open', $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'Boom');

        $summary = $this->getJson('/api/admin/v1/js-errors/summary', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $summary['open']);
        $this->assertSame(1, $summary['resolved']);
        $this->assertSame(3, $summary['occurrences']);
    }

    public function test_js_errors_resolve_unresolve_and_resolve_all(): void
    {
        $error = ClientError::create([
            'fingerprint' => 'fp-1', 'kind' => 'error', 'message' => 'Boom',
            'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->patchJson("/api/admin/v1/js-errors/{$error->id}/resolve", [], $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.is_resolved', true);

        $this->patchJson("/api/admin/v1/js-errors/{$error->id}/unresolve", [], $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.is_resolved', false);

        $this->patchJson('/api/admin/v1/js-errors/resolve-all', [], $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.resolved_count', 1);

        $this->assertNotNull($error->fresh()->resolved_at);
    }

    public function test_js_errors_delete_removes_the_row(): void
    {
        $error = ClientError::create([
            'fingerprint' => 'fp-del', 'kind' => 'error', 'message' => 'Gone soon',
            'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->deleteJson("/api/admin/v1/js-errors/{$error->id}", [], $this->adminApiHeaders())
            ->assertNoContent();

        $this->assertSame(0, ClientError::count());
    }

    // -- Social media ------------------------------------------------------

    public function test_social_media_index_reports_stats_and_platform_roster(): void
    {
        $data = $this->getJson('/api/admin/v1/social-media', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('configured', $data);
        $this->assertNotEmpty($data['platforms']);
        $this->assertSame(array_keys(config('social-platforms')), array_column($data['platforms'], 'key'));
    }

    public function test_save_urls_persists_and_validates(): void
    {
        $this->putJson('/api/admin/v1/social-media/urls', [
            'urls' => ['instagram' => 'not-a-url'],
        ], $this->adminApiHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['urls.instagram']]);

        $this->putJson('/api/admin/v1/social-media/urls', [
            'urls' => ['instagram' => 'https://www.instagram.com/testbiz/'],
        ], $this->adminApiHeaders())
            ->assertOk();

        $this->assertSame(
            'https://www.instagram.com/testbiz/',
            PlatformSetting::get('socials.url.instagram'),
        );
    }

    public function test_post_now_rejects_when_no_platform_is_connected(): void
    {
        config([
            'services.meta.enabled' => false,
            'services.google.business_profile.enabled' => false,
        ]);

        Queue::fake();

        $this->postJson('/api/admin/v1/social-media/post', ['platform' => 'all'], $this->adminApiHeaders())
            ->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_post_now_reports_no_images_without_dispatching(): void
    {
        // Instagram "configured" per MetaSocialService, but no eligible,
        // unposted image exists — pickRandomUnposted() returns null.
        config([
            'services.meta.enabled' => true,
            'services.meta.page_access_token' => 'token',
            'services.meta.instagram_account_id' => '123',
        ]);

        Queue::fake();

        $response = $this->postJson('/api/admin/v1/social-media/post', ['platform' => 'instagram'], $this->adminApiHeaders())
            ->assertOk();

        $this->assertSame('no_images', $response->json('data.status'));
        Queue::assertNothingPushed();
    }

    public function test_post_now_dispatches_exactly_one_job_when_an_image_is_eligible(): void
    {
        config([
            'services.meta.enabled' => true,
            'services.meta.page_access_token' => 'token',
            'services.meta.instagram_account_id' => '123',
        ]);

        $image = $this->eligibleImage($this->publishedProject('kitchen'));

        Queue::fake();

        $response = $this->postJson('/api/admin/v1/social-media/post', ['platform' => 'instagram'], $this->adminApiHeaders())
            ->assertOk();

        $this->assertSame('queued', $response->json('data.status'));
        Queue::assertPushedOn('social-media', PublishToSocialMediaJob::class);
        Queue::assertPushed(PublishToSocialMediaJob::class, fn ($job) => $job->image->id === $image->id);
    }

    public function test_index_never_returns_yelp_images_when_a_non_yelp_platform_filter_is_set(): void
    {
        $data = $this->getJson('/api/admin/v1/social-media?platform=instagram', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertNull($data['yelp_images']);
    }

    public function test_uploaded_posts_carries_nested_project_image_context(): void
    {
        $project = $this->publishedProject('kitchen');
        $image = $this->eligibleImage($project);
        ImageSocialPost::create([
            'project_image_id' => $image->id,
            'platform' => 'instagram',
            'status' => 'published',
            'caption' => 'Look at this kitchen',
            'published_at' => now(),
        ]);

        $data = $this->getJson('/api/admin/v1/social-media', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['uploaded_posts']);
        $this->assertSame('Test Kitchen', $data['uploaded_posts'][0]['project_image']['project_title']);
    }
}
