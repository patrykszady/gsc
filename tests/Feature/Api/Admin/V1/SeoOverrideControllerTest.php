<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

class SeoOverrideControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    private function makeProject(): Project
    {
        return Project::create([
            'title' => 'Modern Kitchen Remodel',
            'slug' => 'modern-kitchen-remodel-'.str()->random(6),
            'project_type' => 'kitchen',
        ]);
    }

    public function test_show_returns_null_fields_when_nothing_has_been_saved_yet(): void
    {
        $project = $this->makeProject();

        $data = $this->getJson("/api/admin/v1/seo/overrides/project/{$project->id}", $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertNull($data['title']);
        $this->assertNull($data['canonical_url']);
    }

    public function test_show_404s_for_an_unknown_type(): void
    {
        $project = $this->makeProject();

        $this->getJson("/api/admin/v1/seo/overrides/not-a-type/{$project->id}", $this->adminApiHeaders())
            ->assertNotFound();
    }

    public function test_show_404s_for_a_missing_record(): void
    {
        $this->getJson('/api/admin/v1/seo/overrides/project/999999', $this->adminApiHeaders())
            ->assertNotFound();
    }

    public function test_update_saves_overrides_and_blanks_normalize_to_null(): void
    {
        $project = $this->makeProject();

        $data = $this->putJson("/api/admin/v1/seo/overrides/project/{$project->id}", [
            'title' => 'Custom Title',
            'description' => '  ',
            'canonical_url' => 'https://gs.construction/portfolio/custom',
            'robots' => 'noindex,follow',
        ], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame('Custom Title', $data['title']);
        $this->assertNull($data['description']);
        $this->assertSame('https://gs.construction/portfolio/custom', $data['canonical_url']);
        $this->assertSame('SEO overrides saved.', $data['message']);

        // Round trip: a second GET sees what was just saved.
        $reread = $this->getJson("/api/admin/v1/seo/overrides/project/{$project->id}", $this->adminApiHeaders())->json('data');
        $this->assertSame('Custom Title', $reread['title']);
    }

    public function test_update_rejects_an_invalid_canonical_url(): void
    {
        $project = $this->makeProject();

        $this->putJson("/api/admin/v1/seo/overrides/project/{$project->id}", [
            'canonical_url' => 'not-a-url',
        ], $this->adminApiHeaders())
            ->assertStatus(422);
    }

    public function test_destroy_clears_all_overrides(): void
    {
        $project = $this->makeProject();

        $this->putJson("/api/admin/v1/seo/overrides/project/{$project->id}", ['title' => 'Temp'], $this->adminApiHeaders())
            ->assertOk();

        $this->deleteJson("/api/admin/v1/seo/overrides/project/{$project->id}", [], $this->adminApiHeaders())
            ->assertNoContent();

        $data = $this->getJson("/api/admin/v1/seo/overrides/project/{$project->id}", $this->adminApiHeaders())->json('data');
        $this->assertNull($data['title']);
    }
}
