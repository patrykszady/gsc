<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\BlogPost;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * The central admin's blog screens talk to this controller. The first
 * version shipped without the BuildsApiResponses trait and 500'd on the
 * very first list call — the ss-systems side only ever saw Http::fake.
 */
class BlogPostControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    private function draft(array $overrides = []): BlogPost
    {
        $project = Project::create(['title' => 'Grand Room Remodel', 'project_type' => 'kitchen', 'location' => 'Mount Prospect, IL', 'is_published' => true]);

        return BlogPost::create(array_merge([
            'project_id' => $project->id,
            'title' => 'A Modern Grand Room in Mount Prospect',
            'excerpt' => 'How we did it.',
            'body' => "Intro.\n\n[cover]\n\n## The build\n\nText.",
            'status' => BlogPost::STATUS_DRAFT,
            'writer' => 'ai',
        ], $overrides));
    }

    public function test_index_lists_posts_with_pagination_meta(): void
    {
        $this->draft();

        $json = $this->getJson('/api/admin/v1/blog-posts', $this->adminApiHeaders())->assertOk()->json();

        $this->assertCount(1, $json['data']);
        $this->assertSame('draft', $json['data'][0]['status']);
        $this->assertSame('Grand Room Remodel', $json['data'][0]['project_title']);
        $this->assertArrayHasKey('last_page', $json['meta']);
    }

    public function test_index_filters_by_status(): void
    {
        $this->draft();

        $json = $this->getJson('/api/admin/v1/blog-posts?status=published', $this->adminApiHeaders())->assertOk()->json();

        $this->assertCount(0, $json['data']);
    }

    public function test_publishing_stamps_published_at_and_edits_mark_manual(): void
    {
        $post = $this->draft();

        $json = $this->putJson("/api/admin/v1/blog-posts/{$post->id}", ['status' => 'published', 'title' => 'Edited'], $this->adminApiHeaders())
            ->assertOk()->json('data');

        $this->assertSame('published', $json['status']);
        $this->assertNotNull($json['published_at']);
        $this->assertSame('manual', $json['writer']);
        $this->assertTrue($post->fresh()->isPublished());
    }

    public function test_slug_edits_stay_unique(): void
    {
        $this->draft(['slug' => 'taken']);
        $post = $this->draft(['title' => 'Second post']);

        $json = $this->putJson("/api/admin/v1/blog-posts/{$post->id}", ['slug' => 'taken'], $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertSame('taken-2', $json['slug']);
    }

    public function test_destroy_removes_the_post(): void
    {
        $post = $this->draft();

        $this->deleteJson("/api/admin/v1/blog-posts/{$post->id}", [], $this->adminApiHeaders())->assertNoContent();

        $this->assertDatabaseMissing('blog_posts', ['id' => $post->id]);
    }

    public function test_drafts_are_not_public_but_open_with_the_signed_preview_link(): void
    {
        $draft = $this->draft();

        $this->get('/blog/' . $draft->slug)->assertNotFound();
        $this->get('/blog/' . $draft->slug . '?preview=1')->assertNotFound();
        $this->get($draft->previewUrl())->assertOk()->assertSee('Draft preview');

        $tampered = preg_replace('/signature=\w+/', 'signature=deadbeef', $draft->previewUrl());
        $this->get($tampered)->assertNotFound();

        $this->getJson("/api/admin/v1/blog-posts/{$draft->id}", $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.preview_url', fn ($url) => str_contains($url, 'signature='));

        $draft->update(['status' => BlogPost::STATUS_PUBLISHED, 'published_at' => now()->subMinute()]);
        $this->get('/blog/' . $draft->slug)->assertOk();
    }
}
