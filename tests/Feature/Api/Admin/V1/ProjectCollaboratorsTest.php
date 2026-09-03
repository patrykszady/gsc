<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\FetchCollaboratorSiteJob;
use App\Models\Project;
use App\Services\Blog\PartnerSiteFetcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

class ProjectCollaboratorsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    private function project(): Project
    {
        return Project::create(['title' => 'Grand Room Remodel', 'project_type' => 'kitchen', 'location' => 'Mount Prospect, IL', 'is_published' => true]);
    }

    public function test_update_stores_collaborators_and_queues_site_fetch(): void
    {
        Queue::fake();
        $project = $this->project();

        $this->putJson("/api/admin/v1/projects/{$project->id}", [
            'title' => $project->title,
            'project_type' => 'kitchen',
            'collaborators' => [
                ['role' => 'interior-designers', 'name' => 'J. Peterson Design', 'url' => 'jpeterson-design.com', 'note' => 'Layout and finishes'],
                ['role' => 'other', 'name' => 'Local Tile Co', 'url' => null],
            ],
        ], $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.collaborators.0.name', 'J. Peterson Design')
            ->assertJsonPath('data.collaborators.0.url', 'https://jpeterson-design.com')
            ->assertJsonPath('data.collaborators.0.role_label', 'Interior Designer')
            ->assertJsonPath('data.collaborators.1.role_label', 'Other partner');

        $this->assertSame(2, $project->collaborators()->count());
        Queue::assertPushed(FetchCollaboratorSiteJob::class, 1);

        // A re-save with the same rows keeps the records (and their cached site text).
        $project->collaborators()->first()->update(['site_title' => 'JPD', 'site_fetched_at' => now()]);
        $this->putJson("/api/admin/v1/projects/{$project->id}", [
            'title' => $project->title,
            'project_type' => 'kitchen',
            'collaborators' => [['role' => 'interior-designers', 'name' => 'J. Peterson Design', 'url' => 'https://jpeterson-design.com']],
        ], $this->adminApiHeaders())->assertOk();

        $this->assertSame(1, $project->collaborators()->count());
        $this->assertSame('JPD', $project->collaborators()->first()->site_title);
    }

    public function test_unknown_role_is_rejected_and_absent_key_leaves_rows_alone(): void
    {
        $project = $this->project();
        $project->collaborators()->create(['role' => 'architects', 'name' => 'Plan Studio']);

        $this->putJson("/api/admin/v1/projects/{$project->id}", [
            'title' => $project->title,
            'project_type' => 'kitchen',
            'collaborators' => [['role' => 'wizard', 'name' => 'X']],
        ], $this->adminApiHeaders())->assertStatus(422);

        $this->putJson("/api/admin/v1/projects/{$project->id}", [
            'title' => 'Renamed',
            'project_type' => 'kitchen',
        ], $this->adminApiHeaders())->assertOk();

        $this->assertSame(1, $project->collaborators()->count());
    }

    public function test_types_exposes_roles_and_design_partner_suggestions(): void
    {
        $this->getJson('/api/admin/v1/projects/types', $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.collaborator_roles.architects', 'Architect')
            ->assertJsonPath('data.collaborator_roles.other', 'Other partner')
            ->assertJsonFragment(['name' => 'J. Peterson Design', 'source' => 'design-partners']);
    }

    public function test_directory_remembers_every_partner_added_on_any_project_and_reuses_the_site_read(): void
    {
        Queue::fake();
        $first = $this->project();
        $first->collaborators()->create(['role' => 'architects', 'name' => 'Plan Studio', 'url' => 'https://plan.test', 'note' => 'Permit drawings', 'site_title' => 'Plan Studio Architects', 'site_fetched_at' => now()]);

        $directory = $this->getJson('/api/admin/v1/projects/types', $this->adminApiHeaders())->json('data.collaborator_suggestions');
        $this->assertSame('Plan Studio', $directory[0]['name']);
        $this->assertSame('Permit drawings', $directory[0]['note']);
        $this->assertSame('projects', $directory[0]['source']);

        // Adding the same partner to another project copies the cached site read; no new fetch.
        $second = Project::create(['title' => 'Second Job', 'project_type' => 'bathroom', 'is_published' => true]);
        $this->putJson("/api/admin/v1/projects/{$second->id}", [
            'title' => 'Second Job', 'project_type' => 'bathroom',
            'collaborators' => [['role' => 'architects', 'name' => 'Plan Studio', 'url' => 'https://plan.test']],
        ], $this->adminApiHeaders())->assertOk();

        $this->assertSame('Plan Studio Architects', $second->collaborators()->first()->site_title);
        Queue::assertNotPushed(FetchCollaboratorSiteJob::class);

        // Still one directory entry for them.
        $names = collect($this->getJson('/api/admin/v1/projects/types', $this->adminApiHeaders())->json('data.collaborator_suggestions'))->pluck('name');
        $this->assertSame(1, $names->filter(fn ($n) => $n === 'Plan Studio')->count());
    }

    public function test_site_fetcher_parses_title_description_and_text(): void
    {
        $parsed = app(PartnerSiteFetcher::class)->parse('<html><head><title>Plan Studio &amp; Co</title><meta name="description" content="Residential architects in Evanston."></head><body><nav>Menu</nav><script>x()</script><h1>Plan Studio</h1><p>We draw additions.</p></body></html>');

        $this->assertSame('Plan Studio & Co', $parsed['site_title']);
        $this->assertSame('Residential architects in Evanston.', $parsed['site_description']);
        $this->assertSame('Plan Studio We draw additions.', $parsed['site_excerpt']);
    }
}
