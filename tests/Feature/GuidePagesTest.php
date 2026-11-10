<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\LandingPage;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\User;
use App\Support\Seo\CrawlFiles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The guide template (LandingPage template "guide", /remodeling/{slug}): the
 * long-form answer to a search that is not a town search. Renders through
 * the same LandingPageShow as the town template — published only, admin
 * preview of drafts, noindex without proof — and joins the sitemap, the
 * llms-full list and the AI feed under the same published + indexed +
 * proof gate. The town template's own view is untouched.
 */
class GuidePagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        // ProjectObserver geocodes a project's town over real HTTP on save,
        // and ProjectImageObserver queues Gemini work whenever a key is set.
        config([
            'services.google.business_profile.auto_geocode_on_project_save' => false,
            'services.google.gemini_api_key' => '',
        ]);
    }

    private function project(string $type = 'bathroom', string $title = 'Palatine Hall Bath', bool $published = true, bool $featured = false): Project
    {
        $project = Project::create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.uniqid(),
            'project_type' => $type,
            'location' => 'Palatine, IL',
            'is_published' => $published,
            'is_featured' => $featured,
            'description' => 'A hall bath rebuilt with a walk-in shower.',
        ]);

        $path = 'projects/'.uniqid().'.jpg';
        Storage::disk('public')->put($path, 'jpg');
        ProjectImage::create([
            'project_id' => $project->id,
            'filename' => basename($path),
            'original_filename' => basename($path),
            'path' => $path,
            'alt_text' => 'A finished bathroom',
        ]);

        return $project;
    }

    /** @param  array<string,mixed>  $overrides */
    private function guide(array $overrides = []): LandingPage
    {
        $page = new LandingPage;
        $page->forceFill(array_merge([
            'slug' => 'small-bathroom-remodels',
            'template' => LandingPage::TEMPLATE_GUIDE,
            'writer' => 'ai',
            'service' => 'bathroom-remodeling',
            'city' => null,
            'modifier' => 'small-space',
            'target_query' => 'bathroom remodels for small bathrooms',
            'title' => 'Small Bathroom Remodels: What Works and What It Costs',
            'h1' => 'Bathroom remodels for small bathrooms: what works and what it costs',
            'meta_description' => 'What works in a small bathroom and what a hall bath remodel costs in the Chicago suburbs. Free in-home estimate.',
            'intro' => "A small bathroom rewards layout decisions more than any finish.\n\nKeep the plumbing where it is and spend on the shower.",
            'sections' => [[
                'heading' => 'Keep the plumbing where it is',
                'body' => "Moving a toilet a few feet adds plumbing and floor work.\n\n- Walk-in shower instead of a tub\n- Wall-hung vanity\n- Large-format tile",
            ]],
            'faq' => [['q' => 'How much does a small bathroom remodel cost?', 'a' => 'A hall bath remodel runs $15,000–$30,000 in the existing layout.']],
            'status' => LandingPage::STATUS_PUBLISHED,
            'indexed' => true,
            'published_at' => now()->subDay(),
            'source' => 'manual',
        ], $overrides))->save();

        return $page;
    }

    public function test_a_published_guide_renders_its_h1_faq_schema_proof_cards_and_links(): void
    {
        $project = $this->project();
        $page = $this->guide(['proof_project_ids' => [$project->id]]);

        $html = $this->get($page->url())->assertOk()->getContent();

        $this->assertStringContainsString('Bathroom remodels for small bathrooms: what works and what it costs', $html);
        $this->assertStringContainsString('"FAQPage"', $html);
        $this->assertStringContainsString('How much does a small bathroom remodel cost?', $html);
        $this->assertStringContainsString('"Article"', $html, 'an indexable guide carries Article structured data');
        $this->assertStringContainsString('data-guide-proof', $html);
        $this->assertStringContainsString('Palatine Hall Bath', $html, 'the proof projects render as cards');
        $this->assertStringContainsString('<li>Walk-in shower instead of a tub</li>', $html, 'a run of "- " lines renders as a list');
        $this->assertStringContainsString(url('/services/bathroom-remodeling'), $html, 'links to the matching service page');
        $this->assertStringContainsString(route('costs.show', ['slug' => 'bathroom-remodel-cost']), $html, 'links to the service\'s published cost guide');
        $this->assertStringContainsString(route('permits.index'), $html);
        $this->assertStringContainsString(url('/areas-served'), $html, 'links to the areas-served pages');
        $this->assertSame(1, substr_count($html, '"BreadcrumbList"'));
        $this->assertStringNotContainsString('noindex', $html);
    }

    public function test_a_draft_guide_is_404_for_the_public_and_previewable_by_an_admin(): void
    {
        $project = $this->project();
        $page = $this->guide(['status' => LandingPage::STATUS_DRAFT, 'published_at' => null, 'proof_project_ids' => [$project->id]]);

        $this->get($page->url())->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->get($page->url().'?preview=1')
            ->assertOk()
            ->assertSee('noindex', false);
    }

    public function test_a_published_guide_without_proof_renders_noindex_and_no_article_schema(): void
    {
        $page = $this->guide(['proof_project_ids' => []]);

        $html = $this->get($page->url())->assertOk()->getContent();

        $this->assertStringContainsString('noindex', $html);
        $this->assertStringNotContainsString('"Article"', $html);
        $this->assertStringNotContainsString('data-guide-proof', $html);
    }

    public function test_guide_proof_is_the_published_work_of_its_service_with_the_chosen_projects_first(): void
    {
        $chosen = $this->project('bathroom', 'Chosen Bath');
        $featured = $this->project('bathroom', 'Featured Bath', featured: true);
        $hidden = $this->project('bathroom', 'Hidden Bath', published: false);
        $this->project('kitchen', 'A Kitchen');

        $page = $this->guide(['proof_project_ids' => [$hidden->id, $chosen->id]]);

        $this->assertSame(
            ['Chosen Bath', 'Featured Bath'],
            $page->proofProjects()->pluck('title')->all(),
            'stored picks first (unpublished dropped), topped up with the service\'s other published projects, never another service\'s'
        );
        $this->assertSame([$featured->id], LandingPage::query()->firstOrFail()->proofProjects()->where('is_featured', true)->pluck('id')->all());
    }

    public function test_guides_join_the_sitemap_llms_full_and_ai_feed_only_when_published_and_indexed(): void
    {
        $project = $this->project();
        $live = $this->guide(['slug' => 'live-guide', 'proof_project_ids' => [$project->id]]);
        $draft = $this->guide(['slug' => 'draft-guide', 'status' => LandingPage::STATUS_DRAFT, 'published_at' => null, 'proof_project_ids' => [$project->id]]);
        $unindexed = $this->guide(['slug' => 'unindexed-guide', 'indexed' => false, 'proof_project_ids' => [$project->id]]);

        $this->artisan('sitemap:generate', ['--url' => 'https://gs.construction'])->assertSuccessful();
        $sitemap = (string) file_get_contents(CrawlFiles::sitemapPath());
        $this->assertStringContainsString('https://gs.construction/remodeling/live-guide', $sitemap);
        $this->assertStringNotContainsString('/remodeling/draft-guide', $sitemap);
        $this->assertStringNotContainsString('/remodeling/unindexed-guide', $sitemap);

        Cache::flush();
        $llms = $this->get('/llms-full.txt')->assertOk()->getContent();
        $this->assertStringContainsString('## Remodeling guides', $llms);
        $this->assertStringContainsString($live->url(), $llms);
        $this->assertStringNotContainsString($draft->url(), $llms);
        $this->assertStringNotContainsString($unindexed->url(), $llms);

        $feed = $this->getJson('/ai-feed.json')->assertOk()->json();
        $this->assertSame([$live->url()], array_column($feed['guides'], 'url'));
        $this->assertSame('guide', $feed['guides'][0]['kind']);
        $this->assertSame('bathroom remodels for small bathrooms', $feed['guides'][0]['answers']);
    }

    public function test_guides_are_linked_from_the_blog_the_cost_pages_and_their_service_page(): void
    {
        $project = $this->project();
        $page = $this->guide(['proof_project_ids' => [$project->id]]);
        $draft = $this->guide(['slug' => 'draft-guide', 'status' => LandingPage::STATUS_DRAFT, 'published_at' => null, 'proof_project_ids' => [$project->id]]);

        $this->get('/blog')->assertOk()->assertSee('Guides')->assertSee($page->url())->assertDontSee($draft->url());
        $this->get('/costs')->assertOk()->assertSee('Guides')->assertSee($page->url());
        $this->get('/costs/bathroom-remodel-cost')->assertOk()->assertSee($page->url());
        $this->get('/costs/kitchen-remodel-cost')->assertOk()->assertDontSee($page->url());
        $this->get('/services/bathroom-remodeling')->assertOk()->assertSee('In-depth guides')->assertSee($page->url());
        $this->get('/services/kitchen-remodeling')->assertOk()->assertDontSee($page->url());

        $post = new BlogPost;
        $post->forceFill(['project_id' => $project->id, 'slug' => 'bath-story', 'title' => 'Bath story', 'excerpt' => 'Story.', 'body' => '<p>Story.</p>', 'status' => BlogPost::STATUS_PUBLISHED, 'published_at' => now()->subDay()])->save();
        $this->get($post->url())->assertOk()->assertSee($page->url());

        $kitchen = $this->project('kitchen', 'A Kitchen');
        $kitchenPost = new BlogPost;
        $kitchenPost->forceFill(['project_id' => $kitchen->id, 'slug' => 'kitchen-story', 'title' => 'Kitchen story', 'excerpt' => 'Story.', 'body' => '<p>Story.</p>', 'status' => BlogPost::STATUS_PUBLISHED, 'published_at' => now()->subDay()])->save();
        $this->get($kitchenPost->url())->assertOk()->assertDontSee($page->url());
    }

    public function test_the_town_template_keeps_its_own_view(): void
    {
        // No proof ids on purpose: the town template's own proof query orders
        // by MySQL's FIELD(), which this SQLite suite does not have — the
        // view choice is what this pins, not that query.
        $town = $this->guide([
            'slug' => 'bathroom-remodeling-palatine',
            'template' => LandingPage::TEMPLATE_SERVICE_MODIFIER_CITY,
            'city' => 'Palatine',
            'modifier' => null,
            'proof_project_ids' => [],
        ]);

        $html = $this->get($town->url())->assertOk()->getContent();

        $this->assertStringContainsString('Bathroom Remodeling in Palatine — Common Questions', $html, 'the town template keeps its own FAQ heading');
        $this->assertStringNotContainsString('data-landing-page-guide', $html);
        $this->assertFalse($town->isGuide());
    }

    public function test_section_blocks_split_paragraphs_and_bullet_lists(): void
    {
        $page = new LandingPage;
        $page->forceFill(['sections' => [[
            'heading' => 'What a permit covers',
            'body' => "First paragraph\nwrapped onto two lines.\n\n- Plumbing\n- Electrical\n\nLast paragraph.",
        ]]]);

        $this->assertSame([[
            'heading' => 'What a permit covers',
            'blocks' => [
                ['type' => 'p', 'text' => 'First paragraph wrapped onto two lines.'],
                ['type' => 'ul', 'items' => ['Plumbing', 'Electrical']],
                ['type' => 'p', 'text' => 'Last paragraph.'],
            ],
        ]], $page->sectionBlocks());
    }
}
