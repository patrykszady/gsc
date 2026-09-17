<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\BlogPost;
use App\Models\Project;
use Tests\TestCase;

/**
 * A blog post used to be linked from the /blog index alone, and Google left
 * it unindexed. Now the three newest posts are linked from the home page,
 * each trade's page, the town's page for that trade, and every other post.
 */
class BlogCrossLinksTest extends TestCase
{
    private function makePost(string $slug, string $title, string $type, string $location): BlogPost
    {
        $project = new Project;
        $project->forceFill(['title' => $title.' project', 'slug' => $slug.'-project', 'project_type' => $type, 'location' => $location, 'is_published' => true, 'description' => 'A project.'])->save();

        $post = new BlogPost;
        $post->forceFill([
            'project_id' => $project->id, 'slug' => $slug, 'title' => $title, 'excerpt' => "The story of the {$title}.", 'body' => "<p>Long story about the {$title}.</p>",
            'status' => BlogPost::STATUS_PUBLISHED, 'published_at' => now()->subDay(), 'dated_at' => now()->subMonth(),
        ])->save();

        return $post;
    }

    public function test_the_newest_posts_are_linked_from_the_home_page_the_trade_pages_and_other_posts(): void
    {
        AreaServed::create(['city' => 'Barrington', 'slug' => 'barrington', 'state' => 'IL', 'local_intro' => str_repeat('Barrington copy. ', 40)]);
        $kitchen = $this->makePost('a-bright-kitchen-in-barrington', 'Bright kitchen in Barrington', 'kitchen', 'Barrington, IL');
        $bath = $this->makePost('a-calm-bathroom-in-palatine', 'Calm bathroom in Palatine', 'bathroom', 'Palatine, IL');

        $this->get('/')->assertOk()->assertSee($kitchen->url())->assertSee($bath->url())->assertSee('From the blog');

        // The kitchen page links the kitchen story; the bathroom page the bathroom one first.
        $this->get('/services/kitchen-remodeling')->assertOk()->assertSee($kitchen->url())->assertSeeInOrder([$kitchen->url(), $bath->url()]);
        $this->get('/services/bathroom-remodeling')->assertOk()->assertSeeInOrder([$bath->url(), $kitchen->url()]);

        // The town's kitchen page leads with its own town's story.
        $this->get('/areas-served/barrington/services/kitchen-remodeling')->assertOk()->assertSee($kitchen->url());

        // A post links onward to the others, never to itself.
        $this->get($bath->url())->assertOk()->assertSee('More from the blog')->assertSee($kitchen->url());
        $this->get($kitchen->url())->assertOk()->assertSee($bath->url());
        $html = $this->get($kitchen->url())->getContent();
        $strip = substr($html, strpos($html, 'data-blog-strip'));
        $this->assertStringNotContainsString($kitchen->url(), $strip);
    }
}
