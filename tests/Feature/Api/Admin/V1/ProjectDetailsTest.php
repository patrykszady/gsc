<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\GenerateAiContentJob;
use App\Jobs\GenerateProjectBlogPostJob;
use App\Jobs\GenerateProjectDetailsJob;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\ProjectSlugHistory;
use App\Models\Site;
use App\Services\AiContentService;
use App\Services\Blog\ProjectBlogWriter;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Projects\ProjectDetailsStatus;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * The photos-first create flow ('ai-project-details'), the same as every
 * ss.systems tenant with projects (ss-platform-kit 0.16.0,
 * docs/PROJECT-DETAILS.md): the admin creates a "New project" draft, adds
 * photos, PUTs the facts without a title, then asks for the title and
 * description to be drafted from the photos. Never calls Gemini for real —
 * AiContentService is mocked in every test that reaches the job's handle().
 */
class ProjectDetailsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
        Storage::fake('public');
    }

    private function draftProject(array $extra = []): Project
    {
        return Project::create($extra + [
            'title' => GenerateProjectDetailsJob::PLACEHOLDER_TITLE,
            'project_type' => 'kitchen',
            'is_published' => false,
            'is_featured' => false,
        ]);
    }

    private function photo(Project $project, string $name = 'kitchen'): ProjectImage
    {
        $file = UploadedFile::fake()->image("{$name}.jpg", 1600, 1200);
        $path = $file->store('projects/'.$project->id, 'public');

        return ProjectImage::create([
            'project_id' => $project->id,
            'filename' => basename($path), 'original_filename' => "{$name}.jpg", 'path' => $path,
            'mime_type' => 'image/jpeg', 'size' => $file->getSize(), 'width' => 1600, 'height' => 1200, 'sort_order' => 0,
        ]);
    }

    private function mockDraft(string $title, string $description): void
    {
        $this->mock(AiContentService::class, fn ($mock) => $mock->shouldReceive('generateWithImages')->once()
            ->andReturn(json_encode(['title' => $title, 'description' => $description])));
    }

    public function test_the_facts_step_saves_without_a_title(): void
    {
        $project = $this->draftProject();

        $this->putJson("/api/admin/v1/projects/{$project->id}", [
            'project_type' => 'bathroom',
            'location' => 'Palatine, IL',
            'completed_at' => '2026-08-01',
            'testimonial_ids' => [],
            'collaborators' => [],
        ], $this->adminApiHeaders())->assertOk()
            ->assertJsonPath('data.title', GenerateProjectDetailsJob::PLACEHOLDER_TITLE)
            ->assertJsonPath('data.project_type', 'bathroom')
            ->assertJsonPath('data.location', 'Palatine, IL');
    }

    public function test_a_sent_title_still_may_not_be_blank_and_create_still_requires_one(): void
    {
        $project = $this->draftProject();

        $this->putJson("/api/admin/v1/projects/{$project->id}", ['title' => ''], $this->adminApiHeaders())
            ->assertStatus(422)->assertJsonValidationErrors('title');

        $this->postJson('/api/admin/v1/projects', ['project_type' => 'kitchen'], $this->adminApiHeaders())
            ->assertStatus(422)->assertJsonValidationErrors('title');
    }

    public function test_generate_details_flags_the_project_and_drafts_after_the_response(): void
    {
        Bus::fake([GenerateProjectDetailsJob::class]);
        $project = $this->draftProject();
        $this->photo($project);

        $this->postJson("/api/admin/v1/projects/{$project->id}/generate-details", [], $this->adminApiHeaders())
            ->assertStatus(202)
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.details.generating', true);

        Bus::assertDispatchedAfterResponse(GenerateProjectDetailsJob::class, fn ($job) => $job->projectId === $project->id && $job->force === false);
    }

    public function test_generate_details_passes_force_through(): void
    {
        Bus::fake([GenerateProjectDetailsJob::class]);
        $project = $this->draftProject(['title' => 'Palatine Kitchen Remodel']);
        $this->photo($project);

        $this->postJson("/api/admin/v1/projects/{$project->id}/generate-details", ['force' => true], $this->adminApiHeaders())
            ->assertStatus(202);

        Bus::assertDispatchedAfterResponse(GenerateProjectDetailsJob::class, fn ($job) => $job->force === true);
    }

    public function test_generate_details_refuses_a_project_without_photos(): void
    {
        $project = $this->draftProject();

        $this->postJson("/api/admin/v1/projects/{$project->id}/generate-details", [], $this->adminApiHeaders())
            ->assertStatus(422)
            ->assertJson(['message' => 'Add at least one photo before generating the details.']);
        $this->assertFalse(Cache::has(ProjectDetailsStatus::flagKey($project->id)));
    }

    public function test_the_payload_reports_no_draft_in_progress_by_default(): void
    {
        $project = $this->draftProject();

        $this->getJson("/api/admin/v1/projects/{$project->id}", $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.details', ['generating' => false, 'error' => null]);
    }

    public function test_the_draft_replaces_the_placeholder_title_and_slug_and_remembers_the_old_slug(): void
    {
        $project = $this->draftProject(['location' => 'Palatine, IL', 'completed_at' => '2026-08-01']);
        $this->photo($project);
        $placeholderSlug = $project->slug;
        ProjectDetailsStatus::markGenerating($project);
        Queue::fake();

        $this->mockDraft('Palatine Kitchen With Quartz Island', 'GS Construction rebuilt this Palatine kitchen around a quartz island.');
        (new GenerateProjectDetailsJob($project->id))->handle(app(AiContentService::class));

        $project->refresh();
        $this->assertSame('Palatine Kitchen With Quartz Island', $project->title);
        $this->assertSame('GS Construction rebuilt this Palatine kitchen around a quartz island.', $project->description);
        $this->assertNotSame($placeholderSlug, $project->slug);
        $this->assertStringStartsWith('palatine-kitchen-with-quartz-island', $project->slug);
        $this->assertSame($project->id, ProjectSlugHistory::where('slug', $placeholderSlug)->value('project_id'));
        $this->assertFalse($project->is_published);
        $this->assertSame(['generating' => false, 'error' => null], ProjectDetailsStatus::status($project));
        Queue::assertPushed(GenerateProjectBlogPostJob::class, fn ($job) => $job->project->is($project));
    }

    public function test_a_real_title_and_description_survive_unless_forced(): void
    {
        $project = $this->draftProject(['title' => 'Our Own Title', 'description' => 'Our own words.']);
        $this->photo($project);
        Queue::fake();

        $this->mockDraft('Model Title', 'Model words.');
        (new GenerateProjectDetailsJob($project->id))->handle(app(AiContentService::class));

        $this->assertSame(['Our Own Title', 'Our own words.'], [$project->fresh()->title, $project->fresh()->description]);
        Queue::assertNotPushed(GenerateProjectBlogPostJob::class);

        $this->mockDraft('Model Title', 'Model words.');
        (new GenerateProjectDetailsJob($project->id, force: true))->handle(app(AiContentService::class));

        $this->assertSame('Model words.', $project->fresh()->description);
    }

    public function test_a_failed_draft_is_reported_and_changes_nothing(): void
    {
        $project = $this->draftProject();
        $this->photo($project);
        ProjectDetailsStatus::markGenerating($project);

        $this->mock(AiContentService::class, function ($mock) {
            $mock->shouldReceive('generateWithImages')->once()->andReturn('not json at all');
        });
        (new GenerateProjectDetailsJob($project->id))->handle(app(AiContentService::class));

        $this->assertSame(GenerateProjectDetailsJob::PLACEHOLDER_TITLE, $project->fresh()->title);
        $status = ProjectDetailsStatus::status($project);
        $this->assertFalse($status['generating']);
        $this->assertNotNull($status['error']);
    }

    public function test_the_draft_prompt_carries_the_facts_and_never_asks_the_model_for_the_type(): void
    {
        $project = $this->draftProject(['project_type' => 'bathroom', 'location' => 'Inverness, IL', 'completed_at' => '2026-06-01']);

        $prompt = (new GenerateProjectDetailsJob($project->id))->prompt($project->fresh(['collaborators', 'testimonials']));

        $this->assertStringContainsString('Location: Inverness, IL.', $prompt);
        $this->assertStringContainsString('Completed: June 2026.', $prompt);
        $this->assertStringContainsString('Type of work: '.$project->typeLabel().'.', $prompt);
        $this->assertStringContainsString('{"title": "...", "description": "..."}', $prompt);
    }

    public function test_another_sites_project_is_404_for_generate_details(): void
    {
        $other = Site::query()->firstOrCreate(['slug' => 'jpeterson'], [
            'name' => 'J. Peterson Design', 'theme' => 'jpeterson', 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com',
        ]);
        $other->forceFill(['is_active' => true])->save();

        $theirs = Tenancy::for($other->fresh(), function () {
            $project = $this->draftProject();
            $this->photo($project);

            return $project->id;
        });
        Site::forgetActive();

        $this->postJson("/api/admin/v1/projects/{$theirs}/generate-details", [], $this->adminApiHeaders())->assertNotFound();
        $this->putJson("/api/admin/v1/projects/{$theirs}", ['location' => 'Elsewhere'], $this->adminApiHeaders())->assertNotFound();
    }

    public function test_the_automatic_description_pass_waits_for_the_drafted_title(): void
    {
        $project = $this->draftProject();
        $this->photo($project);

        $this->mock(AiContentService::class, fn ($mock) => $mock->shouldNotReceive('generateProjectDescription'));
        (new GenerateAiContentJob($project->fresh(), regenerateSitemap: false))->handle(app(AiContentService::class));

        $this->assertNull($project->fresh()->description);
    }

    public function test_the_blog_draft_never_writes_about_a_placeholder_project(): void
    {
        $project = $this->draftProject(['description' => 'Something.']);

        $this->mock(ProjectBlogWriter::class, fn ($mock) => $mock->shouldNotReceive('write'));
        $job = (new GenerateProjectBlogPostJob($project))->withFakeQueueInteractions();
        $job->handle(app(ProjectBlogWriter::class));

        $job->assertReleased(600);
    }
}
