<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\Project;
use App\Models\Testimonial;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * Pixel-parity restorations on the management-API testimonial surface:
 * filters() (distinct project types + the review-platform roster with
 * absolute icon URLs), and review_urls[]/project_ids[] sync on
 * create/update — gsc's replacement for the legacy TestimonialForm's
 * multi-URL rows and Linked Projects picker.
 */
class TestimonialControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    public function test_filters_returns_distinct_project_types_and_known_platform_icons(): void
    {
        Testimonial::create(['reviewer_name' => 'A', 'review_description' => 'x', 'project_type' => 'kitchen']);
        Testimonial::create(['reviewer_name' => 'B', 'review_description' => 'y', 'project_type' => 'bathroom']);

        $data = $this->getJson('/api/admin/v1/testimonials/filters', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(['bathroom', 'kitchen'], $data['project_types']);
        $this->assertSame([], $data['platforms']);
    }

    public function test_filters_lists_platforms_with_icon_for_known_and_null_for_unknown(): void
    {
        $t = Testimonial::create(['reviewer_name' => 'A', 'review_description' => 'x']);
        $t->reviewUrls()->create(['platform' => 'google', 'url' => 'https://google.com/r/1']);
        $t->reviewUrls()->create(['platform' => 'other', 'url' => 'https://example.com/r/2']);

        $data = $this->getJson('/api/admin/v1/testimonials/filters', $this->adminApiHeaders())
            ->assertOk()
            ->json('data.platforms');

        $byValue = collect($data)->keyBy('value');
        $this->assertNotNull($byValue['google']['icon']);
        $this->assertStringContainsString('/images/socials/google.svg', $byValue['google']['icon']);
        $this->assertNull($byValue['other']['icon']);
    }

    public function test_store_syncs_review_urls_and_project_ids(): void
    {
        $project = Project::create([
            'title' => 'Test Project',
            'slug' => 'test-project-'.uniqid(),
            'project_type' => 'kitchen',
            'is_published' => true,
        ]);

        $payload = [
            'reviewer_name' => 'Jane Doe',
            'review_description' => 'Fantastic work on our kitchen remodel.',
            'review_urls' => [
                ['platform' => 'google', 'url' => 'https://google.com/review/abc'],
                ['platform' => '', 'url' => ''], // blank row, must be dropped
            ],
            'project_ids' => [$project->id],
        ];

        $data = $this->postJson('/api/admin/v1/testimonials', $payload, $this->adminApiHeaders())
            ->assertCreated()
            ->json('data');

        $this->assertCount(1, $data['review_urls']);
        $this->assertSame('google', $data['review_urls'][0]['platform']);
        $this->assertSame([$project->id], $data['project_ids']);
        $this->assertStringContainsString('/reviews/', $data['public_url']);

        // Update replaces (not appends) the review_urls set.
        $updated = $this->putJson("/api/admin/v1/testimonials/{$data['id']}", array_merge($payload, [
            'review_urls' => [['platform' => 'yelp', 'url' => 'https://yelp.com/review/xyz']],
            'project_ids' => [],
        ]), $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $updated['review_urls']);
        $this->assertSame('yelp', $updated['review_urls'][0]['platform']);
        $this->assertSame([], $updated['project_ids']);
    }
}
