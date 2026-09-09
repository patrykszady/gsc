<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\GenerateAreaContentJob;
use App\Models\AreaServed;
use App\Models\Project;
use App\Services\AiContentService;
use App\Support\Areas\TownCatalog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * Adding an area from the admin: a dropdown of candidate towns (the Census
 * places around the office that are not areas yet, ranked by demand), and
 * Gemini writing the page content after the row is created.
 */
class AreaCandidatesTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
        Cache::flush();
    }

    public function test_candidates_exclude_existing_areas_and_rank_by_projects_demand_and_service_area(): void
    {
        AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine']);
        // A published project creates its own area (ProjectObserver), so project towns are never candidates.
        Project::create(['title' => 'Wheeling Kitchen', 'slug' => 'wheeling-kitchen', 'project_type' => 'kitchen', 'location' => 'Wheeling, IL', 'is_published' => true]);
        DB::table('seo_keywords')->insert(['site_id' => null, 'keyword' => 'kitchen remodeling mount prospect', 'city' => 'Mount Prospect', 'volume' => 900, 'opportunity' => 50, 'sources' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        config(['gbp-services.service_areas' => ['Des Plaines, IL, USA']]);

        $this->assertGreaterThan(30000, count(TownCatalog::all()), 'the national Census catalog is bundled');

        $data = $this->getJson('/api/admin/v1/areas/candidates', $this->adminApiHeaders())->assertOk()->json('data');
        $names = array_column($data['candidates'], 'name');
        $this->assertNotContains('Palatine', $names, 'already an area');
        $this->assertNotContains('Wheeling', $names, 'the project gave it an area already');
        $this->assertSame('Des Plaines', $names[0], 'Business Profile service area ranks first; got ' . json_encode(array_map(fn ($c) => [$c['name'], $c['score']], array_slice($data['candidates'], 0, 4))));
        $this->assertSame('Mount Prospect', $names[1], 'researched demand ranks next');
        $this->assertContains('Prospect Heights', array_slice($names, 0, 5), 'then distance');
        $desPlaines = collect($data['candidates'])->firstWhere('name', 'Des Plaines');
        $this->assertTrue($desPlaines['in_service_area']);
        $this->assertSame('des-plaines', $desPlaines['slug']);
        $this->assertStringContainsString('Business Profile service area', $desPlaines['why']);
        $this->assertNotNull($desPlaines['latitude']);
        $this->assertStringContainsString('900 searches/mo researched', collect($data['candidates'])->firstWhere('name', 'Mount Prospect')['why']);

        $this->assertSame('Des Plaines', $desPlaines['city'], 'home-state towns keep a bare city');

        // A search covers the whole country: name matches first, bigger places before smaller ones.
        $search = $this->getJson('/api/admin/v1/areas/candidates?q=atlanta', $this->adminApiHeaders())->assertOk()->json('data.candidates');
        $this->assertSame('Atlanta, GA', $search[0]['label']);
        $this->assertSame('atlanta-ga', $search[0]['slug']);
        $this->assertSame('Atlanta, GA', $search[0]['city'], 'out-of-state towns carry their state');
        $this->assertGreaterThan(500, $search[0]['distance_mi']);
        $this->assertContains('Atlanta, IL', array_column($search, 'label'));
        $this->assertTrue(collect($search)->every(fn ($c) => str_contains(strtolower($c['label']), 'atlanta')));
    }

    public function test_creating_from_a_candidate_fills_coordinates_and_queues_the_writer(): void
    {
        Queue::fake();
        $created = $this->postJson('/api/admin/v1/areas', ['name' => 'Wheeling', 'generate' => true], $this->adminApiHeaders())->assertCreated()->json('data');
        $this->assertSame('wheeling', $created['slug']);
        $this->assertEqualsWithDelta(42.13, (float) $created['latitude'], 0.05, 'coordinates come from the catalog, not a geocoder');
        $this->assertTrue($created['generating']);
        Queue::assertPushed(GenerateAreaContentJob::class, fn ($job) => $job->areaId === $created['id'] && $job->force === false);

        // Not asked to generate: nothing queued, nothing flagged.
        $plain = $this->postJson('/api/admin/v1/areas', ['name' => 'Northbrook'], $this->adminApiHeaders())->assertCreated()->json('data');
        $this->assertFalse($plain['generating']);
        Queue::assertPushed(GenerateAreaContentJob::class, 1);

        // Anywhere in the country: the state rides along in the city and the slug.
        $atlanta = $this->postJson('/api/admin/v1/areas', ['name' => 'Atlanta, GA'], $this->adminApiHeaders())->assertCreated()->json('data');
        $this->assertSame('Atlanta, GA', $atlanta['name']);
        $this->assertSame('atlanta-ga', $atlanta['slug']);
        $this->assertEqualsWithDelta(33.76, (float) $atlanta['latitude'], 0.05);

        // On-demand generation for an existing area, with force.
        $this->postJson("/api/admin/v1/areas/{$plain['id']}/generate", ['force' => true], $this->adminApiHeaders())->assertStatus(202);
        Queue::assertPushed(GenerateAreaContentJob::class, fn ($job) => $job->areaId === $plain['id'] && $job->force === true);
        $this->assertTrue($this->getJson("/api/admin/v1/areas/{$plain['id']}", $this->adminApiHeaders())->json('data.generating'));
    }

    public function test_the_job_writes_the_empty_fields_keeps_existing_text_and_clears_the_flag(): void
    {
        $area = AreaServed::create(['city' => 'Wheeling', 'slug' => 'wheeling', 'intro' => 'Kept intro.']);
        $this->mock(AiContentService::class, function ($m) {
            $m->shouldReceive('generateAreaContent')->once()->andReturn(['intro' => 'New intro', 'local_intro' => 'Wheeling homes are mostly 1970s ranches.', 'landmarks' => 'Heritage Park, Chevy Chase', 'permit_notes' => 'Wheeling requires permits.']);
        });
        Cache::put(GenerateAreaContentJob::flagKey($area->id), ['queued_at' => now()->toDateTimeString()], 600);

        (new GenerateAreaContentJob($area->id))->handle(app(AiContentService::class));

        $area->refresh();
        $this->assertSame('Kept intro.', $area->intro, 'existing text survives');
        $this->assertSame('Wheeling homes are mostly 1970s ranches.', $area->local_intro);
        $this->assertSame('Heritage Park, Chevy Chase', $area->landmarks);
        $this->assertNotNull($area->latitude, 'coordinates filled from the catalog');
        $this->assertNull(Cache::get(GenerateAreaContentJob::flagKey($area->id)));
        $this->assertFalse($area->toApiArray()['generating']);
    }

    public function test_a_failed_generation_leaves_an_error_the_admin_can_show(): void
    {
        $area = AreaServed::create(['city' => 'Wheeling', 'slug' => 'wheeling']);
        $this->mock(AiContentService::class, function ($m) {
            $m->shouldReceive('generateAreaContent')->once()->andReturn(null);
            $m->shouldReceive('getLastError')->andReturn('Gemini API key not configured');
        });
        (new GenerateAreaContentJob($area->id))->handle(app(AiContentService::class));
        $api = $area->fresh()->toApiArray();
        $this->assertFalse($api['generating']);
        $this->assertSame('Gemini API key not configured', $api['generation_error']);
    }
}
