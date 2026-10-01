<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\GenerateAreaDescriptionJob;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectImage;
use App\Services\AiContentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * Per-area description drafts — the 2026-10-01 addendum to the project-areas
 * contract adopted from jpeterson-design.com via ss-platform-kit 0.16.0. The
 * admin-facing half: POST generate-description flags the area and queues
 * the draft after the response; the job turns the area's own photos into a
 * description and writes only that field. Never calls Gemini for real —
 * AiContentService is mocked in every test that reaches the job's handle().
 */
class ProjectAreaDescriptionTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
        Storage::fake('public');
    }

    private function areaWithPhoto(array $areaExtra = []): ProjectArea
    {
        $project = Project::create(['title' => 'Mount Prospect Home Remodel', 'project_type' => 'home-remodel', 'location' => 'Mount Prospect, IL', 'completed_at' => '2026-05-01']);
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen'] + $areaExtra);

        $file = UploadedFile::fake()->image('kitchen.jpg', 1600, 1200);
        $path = $file->store('projects/'.$project->id, 'public');
        ProjectImage::create([
            'project_id' => $project->id, 'project_area_id' => $area->id,
            'filename' => basename($path), 'original_filename' => 'kitchen.jpg', 'path' => $path,
            'mime_type' => 'image/jpeg', 'size' => $file->getSize(), 'width' => 1600, 'height' => 1200, 'sort_order' => 0,
        ]);

        return $area;
    }

    public function test_generate_description_flags_the_area_and_queues_the_draft_after_the_response(): void
    {
        Bus::fake([GenerateAreaDescriptionJob::class]);
        $area = $this->areaWithPhoto();

        $response = $this->postJson("/api/admin/v1/projects/{$area->project_id}/areas/{$area->id}/generate-description", [], $this->adminApiHeaders())
            ->assertStatus(202)
            ->assertExactJson(['data' => ['generating' => true]]);

        $this->assertSame((string) strlen($response->getContent()), $response->headers->get('Content-Length'));
        Bus::assertDispatchedAfterResponse(GenerateAreaDescriptionJob::class, fn ($job) => $job->areaId === $area->id);
    }

    public function test_generate_description_refuses_an_area_without_photos(): void
    {
        $project = Project::create(['title' => 'Bare', 'project_type' => 'kitchen']);
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen']);

        $this->postJson("/api/admin/v1/projects/{$project->id}/areas/{$area->id}/generate-description", [], $this->adminApiHeaders())
            ->assertStatus(422)
            ->assertJson(['message' => 'This area has no photos yet.']);
        $this->assertFalse(Cache::has(GenerateAreaDescriptionJob::flagKey($area->id)));
    }

    public function test_the_area_payload_reports_no_generation_by_default(): void
    {
        $project = Project::create(['title' => 'Quiet', 'project_type' => 'kitchen']);
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen']);

        $this->getJson("/api/admin/v1/projects/{$project->id}", $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.areas.0.details', ['generating' => false, 'error' => null]);
    }

    public function test_the_job_writes_only_the_areas_description_from_its_own_photos(): void
    {
        $area = $this->areaWithPhoto(['description' => 'Old write-up.']);
        $project = $area->project;
        Cache::put(GenerateAreaDescriptionJob::flagKey($area->id), 'x', 600);

        $this->mock(AiContentService::class, function ($mock) {
            $mock->shouldReceive('generateWithImages')->once()
                ->withArgs(fn (string $prompt, $images) => count($images) === 1
                    && str_contains($prompt, 'GS Construction') && str_contains($prompt, 'Kitchen'))
                ->andReturn('A pale oak kitchen. Open shelving and a long island.');
        });

        (new GenerateAreaDescriptionJob($area->id))->handle(app(AiContentService::class));

        $area->refresh();
        $this->assertStringContainsString('pale oak kitchen', $area->description);
        $this->assertSame('Mount Prospect Home Remodel', $project->fresh()->title, "the project's own fields are untouched");
        $this->assertSame('kitchen', $area->project_type, "the area's own type is untouched");
        $this->assertFalse(Cache::has(GenerateAreaDescriptionJob::flagKey($area->id)));
        $this->assertNull(Cache::get(GenerateAreaDescriptionJob::errorKey($area->id)));
    }

    public function test_a_rerun_replaces_an_existing_description(): void
    {
        $area = $this->areaWithPhoto(['description' => 'Old write-up.']);

        $this->mock(AiContentService::class, fn ($mock) => $mock->shouldReceive('generateWithImages')->once()
            ->andReturn('Brand new write-up.'));

        (new GenerateAreaDescriptionJob($area->id))->handle(app(AiContentService::class));

        $this->assertSame('Brand new write-up.', $area->refresh()->description);
    }

    public function test_the_facts_given_to_the_prompt_are_the_areas_category_and_the_projects_location_and_date(): void
    {
        $area = $this->areaWithPhoto();

        $seen = null;
        $this->mock(AiContentService::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('generateWithImages')->once()->withArgs(function (string $prompt) use (&$seen) {
                $seen = $prompt;

                return true;
            })->andReturn('Words.');
        });
        (new GenerateAreaDescriptionJob($area->id))->handle(app(AiContentService::class));

        $this->assertStringContainsString('Area: Kitchen Remodel.', $seen);
        $this->assertStringContainsString('Location: Mount Prospect, IL.', $seen);
        $this->assertStringContainsString('Completed: May 2026.', $seen);
    }

    public function test_a_failed_draft_leaves_the_description_alone_and_reports_why(): void
    {
        $area = $this->areaWithPhoto(['description' => 'Kept as-is.']);
        Cache::put(GenerateAreaDescriptionJob::flagKey($area->id), 'x', 600);

        $this->mock(AiContentService::class, function ($mock) {
            $mock->shouldReceive('generateWithImages')->once()->andReturn(null);
            $mock->shouldReceive('getLastError')->andReturn('Gemini API key not configured');
        });

        (new GenerateAreaDescriptionJob($area->id))->handle(app(AiContentService::class));

        $this->assertSame('Kept as-is.', $area->refresh()->description);
        $this->assertFalse(Cache::has(GenerateAreaDescriptionJob::flagKey($area->id)));
        $this->assertSame('Gemini API key not configured', Cache::get(GenerateAreaDescriptionJob::errorKey($area->id)));

        $this->getJson("/api/admin/v1/projects/{$area->project_id}", $this->adminApiHeaders())
            ->assertJsonPath('data.areas.0.details.generating', false)
            ->assertJsonPath('data.areas.0.details.error', 'Gemini API key not configured');
    }
}
