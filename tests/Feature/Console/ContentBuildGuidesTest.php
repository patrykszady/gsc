<?php

namespace Tests\Feature\Console;

use App\Models\LandingPage;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\Site;
use App\Services\AiContentService;
use App\Services\Seo\TitleMetaGenerator;
use App\Support\SiteConfig;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * content:build-guides — the site's guide pages (/remodeling/{slug}, template
 * "guide") written as drafts from config/sites/gsc/content-guides.php
 * through GuidePageContentGenerator, with AiContentService mocked exactly
 * as the other content commands' tests mock it. The real cluster file is
 * read on purpose: a cluster that cannot be built from the site's facts
 * should fail here before it fails on production.
 */
class ContentBuildGuidesTest extends TestCase
{
    private const SLUG = 'small-bathroom-remodels';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        // Observers: no geocoding over HTTP on project save, no Gemini job on
        // image save. Each test sets its own model key after the fixtures.
        config([
            'services.google.business_profile.auto_geocode_on_project_save' => false,
            'services.google.gemini_api_key' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function project(string $type, string $title, bool $withPhoto = true): Project
    {
        $project = Project::create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.uniqid(),
            'project_type' => $type,
            'location' => 'Palatine, IL',
            'is_published' => true,
            'description' => 'A hall bath rebuilt with a walk-in shower, new tile and a wall-hung vanity.',
        ]);

        if ($withPhoto) {
            $path = 'projects/'.uniqid().'.jpg';
            Storage::disk('public')->put($path, 'jpg');
            ProjectImage::create([
                'project_id' => $project->id,
                'filename' => basename($path),
                'original_filename' => basename($path),
                'path' => $path,
                'alt_text' => 'A finished bathroom',
            ]);
        }

        return $project;
    }

    /**
     * A model answer that passes every check: 5 sections, 4 FAQs, about
     * 1,250 words, and only figures the site states ($15,000–$30,000,
     * 3–5 weeks are the bathroom cost guide's own).
     *
     * @param  array<string,mixed>  $overrides
     */
    private function answer(array $overrides = []): string
    {
        $sentence = 'Keep the plumbing where it is and spend the budget on the shower, the tile and the ventilation instead of moving fixtures. ';
        $body = trim(str_repeat($sentence, 11));

        return json_encode(array_merge([
            'title' => 'Small Bathroom Remodels: What Works and What It Costs',
            'meta_description' => 'What works in a small bathroom and what a hall bath remodel costs in the Chicago suburbs, from GS Construction. Free in-home estimate.',
            'h1' => 'Bathroom remodels for small bathrooms: what works and what it costs',
            'intro' => 'A small bathroom rewards layout decisions more than finishes. GS Construction keeps the plumbing where it is, puts the budget into the shower and the tile, and prices the work line by line.',
            'sections' => [
                ['heading' => 'Keep the plumbing where it is', 'body' => $body],
                ['heading' => 'A walk-in shower instead of a tub', 'body' => $body],
                ['heading' => 'Storage that does not eat the floor', 'body' => "$body\n\n- Wall-hung vanity\n- Recessed niche\n- Medicine cabinet"],
                ['heading' => 'What a small bathroom remodel costs', 'body' => 'A hall bath in the existing layout runs $15,000–$30,000 and takes 3–5 weeks on site. '.$body],
                ['heading' => 'How GS Construction runs the job', 'body' => $body],
            ],
            'faq' => [
                ['q' => 'How much does a small bathroom remodel cost?', 'a' => 'A hall or guest bath remodel in the existing layout runs $15,000–$30,000, with the tile scope and the shower system moving the number most. Every GS Construction estimate is itemized and free.'],
                ['q' => 'How long does it take?', 'a' => 'A typical bathroom remodel takes 3–5 weeks on site. We give you a written schedule before demolition and one owner is here while the work happens.'],
                ['q' => 'Should I keep the tub?', 'a' => 'In a small bathroom a walk-in shower usually gives back the most floor and the most light; we talk it through on the free in-home estimate with your own layout in front of us.'],
                ['q' => 'Do you pull the permit?', 'a' => 'Yes. We pull and manage the building, plumbing and electrical permits with your village and schedule every inspection through final sign-off.'],
            ],
        ], $overrides), JSON_UNESCAPED_UNICODE);
    }

    /** @param  list<string|null>  $answers  what generateText returns, call by call */
    private function mockModel(array $answers, array &$prompts = []): void
    {
        $this->mock(AiContentService::class, function (MockInterface $mock) use ($answers, &$prompts) {
            $calls = 0;
            $mock->shouldReceive('generateText')
                ->times(count($answers))
                ->andReturnUsing(function (string $prompt) use ($answers, &$calls, &$prompts) {
                    $prompts[] = $prompt;

                    return $answers[$calls++];
                });
            $mock->shouldReceive('getLastError')->andReturn('Connection error: Gemini did not answer');
        });
    }

    public function test_dry_run_lists_what_it_would_build_without_calling_the_model_or_writing(): void
    {
        $this->project('bathroom', 'Palatine Hall Bath');
        $this->mock(AiContentService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateText'));

        $this->artisan('content:build-guides', ['--dry-run' => true, '--only' => self::SLUG])
            ->expectsOutputToContain('would  /remodeling/'.self::SLUG)
            ->expectsOutputToContain('Dry run: 0 built, 0 skipped (exist), 0 failed.')
            ->assertSuccessful();

        $this->assertSame(0, LandingPage::count());
    }

    public function test_it_writes_a_draft_from_the_models_answer_and_skips_the_slug_on_the_next_run(): void
    {
        $first = $this->project('bathroom', 'Palatine Hall Bath');
        $second = $this->project('bathroom', 'Barrington Primary Bath');
        $this->project('kitchen', 'A Kitchen');
        $prompts = [];
        $this->mockModel([$this->answer()], $prompts);
        config(['services.google.gemini_api_key' => 'test-key']);

        $this->artisan('content:build-guides', ['--only' => self::SLUG, '--delay' => 0])
            ->expectsOutputToContain('saved  /remodeling/'.self::SLUG)
            ->assertSuccessful();

        $page = LandingPage::where('slug', self::SLUG)->firstOrFail();
        $this->assertSame(LandingPage::TEMPLATE_GUIDE, $page->template);
        $this->assertSame(LandingPage::STATUS_DRAFT, $page->status);
        $this->assertSame('manual', $page->source);
        $this->assertTrue($page->indexed);
        $this->assertSame('ai', $page->writer);
        $this->assertSame('bathroom-remodeling', $page->service);
        $this->assertNull($page->city);
        $this->assertSame('small-space', $page->modifier);
        $this->assertSame('bathroom remodels for small bathrooms', $page->target_query);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $page->proof_project_ids, 'proof = the published bathrooms, never the kitchen');
        $this->assertNotNull($page->hero_image, 'the hero is a real photo of a matching project');
        $this->assertCount(5, $page->sections);
        $this->assertCount(4, $page->faq);
        $this->assertLessThanOrEqual(60, mb_strlen($page->title));
        $this->assertLessThanOrEqual(158, mb_strlen($page->meta_description));
        $this->assertTrue($page->hasProof());
        $this->assertFalse($page->shouldIndex(), 'a draft is never indexable until a person publishes it');

        // The prompt hands the model the site's facts — and only those.
        $this->assertStringContainsString('"bathroom remodels for small bathrooms"', $prompts[0]);
        $this->assertStringContainsString('$15,000–$30,000', $prompts[0], 'the published bathroom tiers');
        $this->assertStringContainsString('"Palatine Hall Bath"', $prompts[0], 'the matched projects');
        $this->assertStringContainsString('Never name another contractor', $prompts[0]);

        $this->artisan('content:build-guides', ['--only' => self::SLUG, '--delay' => 0])
            ->expectsOutputToContain('skip   /remodeling/'.self::SLUG.' — exists (draft)')
            ->assertSuccessful();
        $this->assertSame(1, LandingPage::count());
    }

    public function test_force_rewrites_a_draft_but_never_a_published_page(): void
    {
        $this->project('bathroom', 'Palatine Hall Bath');
        $this->mockModel([$this->answer(), $this->answer(['h1' => 'Second draft'])]);
        config(['services.google.gemini_api_key' => 'test-key']);

        $this->artisan('content:build-guides', ['--only' => self::SLUG, '--delay' => 0])->assertSuccessful();
        $page = LandingPage::where('slug', self::SLUG)->firstOrFail();
        $page->update(['status' => LandingPage::STATUS_PUBLISHED, 'published_at' => now()]);

        $this->artisan('content:build-guides', ['--only' => self::SLUG, '--force' => true, '--delay' => 0])
            ->expectsOutputToContain('exists (published)')
            ->assertSuccessful();
        $this->assertNotSame('Second draft', $page->fresh()->h1, 'a published page is never rewritten');

        $page->update(['status' => LandingPage::STATUS_DRAFT, 'published_at' => null]);
        $this->artisan('content:build-guides', ['--only' => self::SLUG, '--force' => true, '--delay' => 0])->assertSuccessful();
        $this->assertSame('Second draft', $page->fresh()->h1);
        $this->assertSame(1, LandingPage::count());
    }

    public function test_only_rejects_an_unknown_slug(): void
    {
        $this->artisan('content:build-guides', ['--only' => 'no-such-guide', '--dry-run' => true])
            ->expectsOutputToContain('Unknown slug(s) in --only: no-such-guide')
            ->assertFailed();
    }

    public function test_it_fails_clearly_without_a_model_and_writes_nothing(): void
    {
        $this->project('bathroom', 'Palatine Hall Bath');
        $this->mock(AiContentService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateText'));

        $this->artisan('content:build-guides', ['--only' => self::SLUG, '--delay' => 0])
            ->expectsOutputToContain('GOOGLE_GEMINI_API_KEY')
            ->assertFailed();

        $this->assertSame(0, LandingPage::count());
    }

    public function test_a_page_fails_when_the_model_does_not_answer(): void
    {
        $this->project('bathroom', 'Palatine Hall Bath');
        $this->mockModel([null]);
        config(['services.google.gemini_api_key' => 'test-key']);

        $this->artisan('content:build-guides', ['--only' => self::SLUG, '--delay' => 0])
            ->expectsOutputToContain('Connection error: Gemini did not answer')
            ->expectsOutputToContain('Done: 0 built, 0 skipped (exist), 1 failed.')
            ->assertFailed();

        $this->assertSame(0, LandingPage::count());
    }

    public function test_a_draft_that_invents_a_price_is_sent_back_once_with_the_reason_then_fails(): void
    {
        $this->project('bathroom', 'Palatine Hall Bath');
        $invented = $this->answer(['intro' => 'Most small bathrooms cost about $12,345 and take 9–11 weeks, which is what we charge.']);
        $prompts = [];
        $this->mockModel([$invented, $invented], $prompts);
        config(['services.google.gemini_api_key' => 'test-key']);

        $this->artisan('content:build-guides', ['--only' => self::SLUG, '--delay' => 0])
            ->expectsOutputToContain('Draft rejected twice')
            ->assertFailed();

        $this->assertSame(0, LandingPage::count());
        $this->assertCount(2, $prompts);
        $this->assertStringContainsString('YOUR PREVIOUS DRAFT WAS REJECTED', $prompts[1]);
        $this->assertStringContainsString('$12345', $prompts[1]);
        $this->assertStringContainsString('9-11 week', $prompts[1]);
    }

    public function test_a_corrected_second_draft_is_saved(): void
    {
        $this->project('bathroom', 'Palatine Hall Bath');
        $this->mockModel([
            $this->answer(['intro' => 'Our competitor Airoom charges more.']),
            $this->answer(),
        ]);
        config(['services.google.gemini_api_key' => 'test-key']);

        $this->artisan('content:build-guides', ['--only' => self::SLUG, '--delay' => 0])->assertSuccessful();

        $this->assertSame(1, LandingPage::count());
        $this->assertStringNotContainsString('Airoom', (string) LandingPage::first()->intro);
    }

    public function test_a_draft_that_is_too_short_is_rejected(): void
    {
        $this->project('bathroom', 'Palatine Hall Bath');
        $short = json_decode($this->answer(), true);
        $short['sections'] = array_map(fn (array $s) => ['heading' => $s['heading'], 'body' => 'Short.'], $short['sections']);
        $this->mockModel([json_encode($short), json_encode($short)]);
        config(['services.google.gemini_api_key' => 'test-key']);

        $this->artisan('content:build-guides', ['--only' => self::SLUG, '--delay' => 0])
            ->expectsOutputToContain('words; the page needs at least')
            ->assertFailed();

        $this->assertSame(0, LandingPage::count());
    }

    public function test_a_cluster_with_no_published_proof_fails_instead_of_building_a_thin_page(): void
    {
        $this->mock(AiContentService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateText'));
        config(['services.google.gemini_api_key' => 'test-key']);

        $this->artisan('content:build-guides', ['--only' => 'laundry-room-remodel-cost', '--delay' => 0])
            ->expectsOutputToContain('no published project of type mudroom')
            ->assertFailed();

        $this->assertSame(0, LandingPage::count());
    }

    public function test_every_configured_cluster_names_a_known_service_and_a_head_query_in_its_own_variants(): void
    {
        $clusters = (array) SiteConfig::forSite(Site::current(), 'content-guides.clusters', []);

        $this->assertCount(20, $clusters);
        $slugs = array_column($clusters, 'slug');
        $this->assertSame($slugs, array_unique($slugs), 'slugs are unique');
        foreach ($clusters as $cluster) {
            $this->assertArrayHasKey($cluster['service'], TitleMetaGenerator::SERVICES, $cluster['slug']);
            $this->assertArrayHasKey($cluster['head_query'], $cluster['queries'], $cluster['slug'].' targets one of its own queries');
            $this->assertNotEmpty($cluster['proof_types'], $cluster['slug']);
            foreach ($cluster['cost_guides'] as $costSlug) {
                $this->assertNotNull(collect(config('remodel-costs.guides'))->firstWhere('slug', $costSlug), "{$cluster['slug']} names a /costs page that exists");
            }
        }
    }
}
