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

    public function test_generate_for_project_queues_the_writer_and_status_reports_progress(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $project = Project::create(['title' => 'Bath', 'project_type' => 'bathroom', 'location' => 'Palatine, IL', 'is_published' => true]);

        $this->getJson("/api/admin/v1/projects/{$project->id}/blog-post", $this->adminApiHeaders())
            ->assertOk()->assertJsonPath('data.post', null)->assertJsonPath('data.generating', false);

        $this->postJson("/api/admin/v1/projects/{$project->id}/blog-post", [], $this->adminApiHeaders())
            ->assertStatus(202)->assertJsonPath('data.generating', true);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\GenerateProjectBlogPostJob::class, fn ($job) => $job->project->is($project) && $job->force);

        // The project payload carries the same summary for the form's card.
        $this->getJson("/api/admin/v1/projects/{$project->id}", $this->adminApiHeaders())
            ->assertOk()->assertJsonPath('data.blog.generating', true);

        // Once the job finishes, the flag is gone and the post is reported.
        \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenerateProjectBlogPostJob::generatingKey($project));
        $this->draft(['project_id' => $project->id, 'title' => 'A Palatine bath']);
        $this->getJson("/api/admin/v1/projects/{$project->id}/blog-post", $this->adminApiHeaders())
            ->assertOk()->assertJsonPath('data.post.title', 'A Palatine bath')->assertJsonPath('data.generating', false)
            ->assertJsonPath('data.post.preview_url', fn ($u) => str_contains($u, 'signature='));
    }

    public function test_publishing_announces_the_post_to_crawlers_and_marks_it_up(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $draft = $this->draft();

        $this->putJson("/api/admin/v1/blog-posts/{$draft->id}", ['status' => 'published'], $this->adminApiHeaders())->assertOk();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\AnnounceBlogPostJob::class, fn ($job) => $job->post->is($draft));

        $html = $this->get('/blog/' . $draft->slug)->assertOk()->getContent();
        $this->assertStringContainsString('"@type":"BlogPosting"', $html);
        $this->assertStringContainsString('property="og:type" content="article"', $html);
        $this->assertStringContainsString('property="article:published_time"', $html);
        $this->assertStringContainsString('property="article:section" content="Kitchen Remodel"', $html);
        $this->assertStringContainsString('name="keywords"', $html);
        $this->assertStringContainsString('type="application/atom+xml" href="' . route('blog.feed') . '"', $html);

        $feed = $this->get('/blog/feed.atom')->assertOk()->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('<title>A Modern Grand Room in Mount Prospect</title>', $feed);
        $this->assertStringContainsString('<published>', $feed);
        $this->assertStringContainsString('/blog/' . $draft->slug, $this->get('/feed/updates.atom')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/"@type":\s*"BreadcrumbList"/', $html);
        $this->assertStringNotContainsString('noindex', $html);

        // A draft is neither marked up nor announced.
        \Illuminate\Support\Facades\Queue::fake();
        $this->putJson("/api/admin/v1/blog-posts/{$draft->id}", ['status' => 'draft'], $this->adminApiHeaders())->assertOk();
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\AnnounceBlogPostJob::class);
    }

    public function test_body_round_trips_through_html_for_the_rich_text_editor(): void
    {
        $draft = $this->draft(['body' => "Intro **bold** text.\n\n[cover]\n\n## The build\n\nA [link](https://example.com) here.\n\n[gallery]"]);

        $html = $this->getJson("/api/admin/v1/blog-posts/{$draft->id}", $this->adminApiHeaders())->assertOk()->json('data.body_html');
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<h2>The build</h2>', $html);
        $this->assertStringContainsString('<p>[cover]</p>', $html);

        $edited = str_replace('<h2>The build</h2>', '<h2>The build, week by week</h2><p>[timelapse]</p>', $html);
        $this->putJson("/api/admin/v1/blog-posts/{$draft->id}", ['body_html' => $edited], $this->adminApiHeaders())->assertOk();

        $body = $draft->fresh()->body;
        $this->assertStringContainsString('**bold**', $body);
        $this->assertStringContainsString('## The build, week by week', $body);
        $this->assertMatchesRegularExpression('/^\[cover\]$/m', $body);
        $this->assertMatchesRegularExpression('/^\[timelapse\]$/m', $body);
        $this->assertMatchesRegularExpression('/^\[gallery\]$/m', $body);
        $this->assertStringContainsString('[link](https://example.com)', $body);
        $this->assertSame('manual', $draft->fresh()->writer);
    }
}
