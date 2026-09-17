<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\BlogPost;
use App\Models\LandingPage;
use App\Models\Project;
use Tests\TestCase;

/**
 * The six in-depth landing pages were linked from nowhere and carried no
 * breadcrumb trail, and Google had them as "Discovered, currently not
 * indexed". A trade page links its own, a town page its town's, and the
 * page itself has a trail like every other page type.
 */
class LandingPageLinksTest extends TestCase
{
    public function test_landing_pages_are_linked_from_their_trade_and_town_and_carry_one_breadcrumb_trail(): void
    {
        AreaServed::create(['city' => 'Glencoe', 'slug' => 'glencoe', 'state' => 'IL', 'local_intro' => str_repeat('Glencoe copy. ', 40)]);
        $page = new LandingPage;
        $page->forceFill([
            'slug' => 'small-space-bathroom-remodeling-glencoe', 'template' => 'service-city', 'service' => 'bathroom-remodeling', 'city' => 'Glencoe',
            'title' => 'Small-Space Bathroom Remodeling in Glencoe', 'h1' => 'Small-space bathroom remodeling in Glencoe', 'meta_description' => 'Guide.',
            'intro' => 'A long guide to small bathrooms in Glencoe.', 'sections' => [], 'faq' => [], 'status' => LandingPage::STATUS_PUBLISHED, 'indexed' => true, 'published_at' => now()->subDay(),
        ])->save();

        $this->get('/services/bathroom-remodeling')->assertOk()->assertSee('In-depth guides')->assertSee($page->url());
        $this->get('/services/kitchen-remodeling')->assertOk()->assertDontSee($page->url());
        $this->get('/areas-served/glencoe')->assertOk()->assertSee('Guides for Glencoe')->assertSee($page->url());

        $html = $this->get($page->url())->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '"BreadcrumbList"'));
        $this->assertStringContainsString('Bathroom Remodeling', $html);
    }

    public function test_a_blog_post_carries_exactly_one_breadcrumb_trail(): void
    {
        $project = new Project;
        $project->forceFill(['title' => 'Kitchen', 'slug' => 'kitchen-x', 'project_type' => 'kitchen', 'location' => 'Barrington, IL', 'is_published' => true, 'description' => 'A project.'])->save();
        $post = new BlogPost;
        $post->forceFill(['project_id' => $project->id, 'slug' => 'kitchen-story', 'title' => 'Kitchen story', 'excerpt' => 'Story.', 'body' => '<p>Story.</p>', 'status' => BlogPost::STATUS_PUBLISHED, 'published_at' => now()->subDay()])->save();

        $html = $this->get($post->url())->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '"BreadcrumbList"'));
    }
}
