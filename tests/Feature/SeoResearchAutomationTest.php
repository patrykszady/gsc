<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\SeoAction;
use App\Services\AiContentService;
use App\Services\Seo\Appliers\ContentRefreshApplier;
use App\Services\Seo\SeoAutopilotService;
use App\Support\SEO\AreaSeoPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Keyword research (seo_keywords) drives the autopilot: landing pages for
 * researched gaps, title experiments on researched phrases, copy refreshes
 * for thin town pages — and town service pages index on researched volume.
 */
class SeoResearchAutomationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function keyword(array $a): void
    {
        DB::table('seo_keywords')->insert(array_merge(['site_id' => null, 'sources' => json_encode(['competitor']), 'opportunity' => 100, 'researched_at' => now(), 'created_at' => now(), 'updated_at' => now()], $a));
    }

    public function test_research_creates_a_landing_page_a_title_experiment_and_a_copy_refresh(): void
    {
        Cache::flush();
        $kenilworth = AreaServed::create(['city' => 'Kenilworth', 'slug' => 'kenilworth', 'local_intro' => 'Short copy about Kenilworth.']);
        Project::create(['title' => 'Palatine Kitchen', 'project_type' => 'kitchen', 'location' => 'Palatine, IL', 'is_published' => true, 'is_featured' => true]);

        // Uncovered town inside the declared service area → landing page (proof comes from the featured kitchen project);
        // an uncovered town outside it is ignored.
        config(['gbp-services.service_areas' => ['Wheeling, IL, USA', 'Palatine, IL, USA']]);
        $this->keyword(['keyword' => 'kitchen remodeling round lake', 'volume' => 200, 'service' => 'kitchen-remodeling', 'city' => 'Round Lake', 'competitor_best_position' => 2]);
        $this->keyword(['keyword' => 'kitchen remodeling wheeling', 'volume' => 140, 'service' => 'kitchen-remodeling', 'city' => 'Wheeling', 'competitor_best_position' => 3, 'competitor_domains' => json_encode(['prism.test' => 3])]);
        // Covered town, plain intent, strong phrase the title lacks → title experiment + copy refresh (thin intro).
        $this->keyword(['keyword' => 'kenilworth home remodeling and renovation services', 'volume' => 320, 'service' => 'home-remodeling', 'city' => 'Kenilworth', 'our_position' => 7.5]);

        // A competitor's brand and a navigational term must not drive anything.
        DB::table('map_pack_competitors')->insert(['site_id' => null, 'place_id' => 'kbu', 'keyword' => 'kitchen remodeling', 'name' => 'Kitchens & Baths Unlimited', 'pack_points' => 3, 'seen_points' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $this->keyword(['keyword' => 'kitchens and baths unlimited kenilworth', 'volume' => 900, 'service' => 'kitchen-remodeling', 'city' => 'Kenilworth']);
        $this->keyword(['keyword' => 'chi renovation kenilworth', 'volume' => 400, 'service' => 'home-remodeling', 'city' => 'Kenilworth', 'intent' => 'navigational']);

        $created = app(SeoAutopilotService::class)->synthesize();
        $this->assertGreaterThanOrEqual(3, $created);

        $page = SeoAction::where('category', 'create_page')->where('source', 'keyword_research')->first();
        $this->assertNotNull($page);
        $this->assertStringContainsString('Wheeling', $page->title);
        $this->assertNull(SeoAction::where('category', 'create_page')->where('title', 'like', '%Round Lake%')->first(), 'towns outside the declared service area get no page');
        $this->assertSame(140, $page->payload['volume']);

        $title = SeoAction::where('category', 'title_meta')->where('source', 'keyword_research')->first();
        $this->assertNotNull($title);
        $this->assertSame('https://gs.construction/areas-served/kenilworth', $title->target_url);
        $this->assertStringContainsString('Renovation Services', $title->payload['new_title']);
        $this->assertSame(0, SeoAction::where('category', 'title_meta')->where('payload', 'like', '%Unlimited%')->count(), 'competitor brands never become our title');
        $this->assertSame(0, SeoAction::where('payload', 'like', '%chi renovation%')->count(), 'navigational terms drive nothing');

        $refresh = SeoAction::where('category', 'content_refresh')->first();
        $this->assertNotNull($refresh);
        $this->assertSame($kenilworth->getKey(), (int) $refresh->target_id);
        $this->assertContains('kenilworth home remodeling and renovation services', $refresh->payload['phrases']);
    }

    public function test_content_refresh_applies_and_reverts_exactly(): void
    {
        $area = AreaServed::create(['city' => 'Kenilworth', 'slug' => 'kenilworth', 'local_intro' => 'Original short copy about Kenilworth.']);
        $this->mock(AiContentService::class, function ($m) {
            $m->shouldReceive('deepenAreaLocalIntro')->once()->andReturn(str_repeat('Deeper copy that mentions Kenilworth homes and remodeling. ', 40));
        });
        $action = SeoAction::create(['fingerprint' => 'f1', 'source' => 'keyword_research', 'category' => 'content_refresh', 'risk' => 'safe', 'status' => 'proposed', 'target_type' => AreaServed::class, 'target_id' => $area->getKey(), 'target_url' => 'https://gs.construction/areas-served/kenilworth', 'title' => 't', 'hypothesis' => 'h', 'metric' => 'clicks', 'payload' => ['phrases' => ['kenilworth home remodeling']]]);

        $applier = new ContentRefreshApplier();
        $applier->apply($action);
        $this->assertStringStartsWith('Deeper copy', $area->fresh()->local_intro);
        $this->assertSame('Original short copy about Kenilworth.', $action->payload['prev_local_intro']);

        $applier->revert($action);
        $this->assertSame('Original short copy about Kenilworth.', $area->fresh()->local_intro);
    }

    public function test_town_service_page_indexes_on_researched_volume_alone(): void
    {
        Cache::flush();
        $area = AreaServed::create(['city' => 'Winnetka', 'slug' => 'winnetka', 'local_intro' => str_repeat('Winnetka copy. ', 50)]);
        $this->assertFalse(AreaSeoPolicy::shouldIndex($area, 'service', 'bathroom-remodeling'));

        Cache::flush();
        $this->keyword(['keyword' => 'bathroom remodeling winnetka', 'volume' => 70, 'service' => 'bathroom-remodeling', 'city' => 'Winnetka']);
        $this->assertSame(70, AreaSeoPolicy::researchVolume($area, 'bathroom-remodeling'));
        $this->assertTrue(AreaSeoPolicy::shouldIndex($area, 'service', 'bathroom-remodeling'));
    }
}
