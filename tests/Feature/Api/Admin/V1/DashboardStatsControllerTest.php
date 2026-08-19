<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\ContactSubmission;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\SeoAction;
use App\Models\Tag;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * The "automation" block restored from the legacy monolith Dashboard. Every
 * source inside it is individually try/caught in the controller, so this
 * covers both the empty-table shape (zeros, not an error) and a populated
 * one.
 */
class DashboardStatsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    public function test_automation_block_is_present_and_zeroed_with_no_seo_data(): void
    {
        $data = $this->getJson('/api/admin/v1/dashboard-stats', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('automation', $data);
        $this->assertSame(0, $data['automation']['open']);
        $this->assertSame(0, $data['automation']['problems']);
        $this->assertSame([], $data['automation']['urgent']);
    }

    public function test_automation_block_reflects_open_and_measuring_actions(): void
    {
        SeoAction::create([
            'fingerprint' => 'fp-open',
            'source' => 'striking_distance',
            'category' => 'title_meta',
            'risk' => SeoAction::RISK_SAFE,
            'title' => 'Open action',
            'status' => SeoAction::STATUS_PROPOSED,
        ]);
        SeoAction::create([
            'fingerprint' => 'fp-measuring',
            'source' => 'striking_distance',
            'category' => 'reindex',
            'risk' => SeoAction::RISK_SAFE,
            'title' => 'Measuring action',
            'status' => SeoAction::STATUS_APPLIED,
            'applied_at' => now(),
            'measure_after' => now()->addDays(21),
        ]);

        $data = $this->getJson('/api/admin/v1/dashboard-stats', $this->adminApiHeaders())
            ->assertOk()
            ->json('data.automation');

        $this->assertSame(1, $data['open']);
        $this->assertSame(1, $data['measuring']);
        $this->assertNotNull($data['next_outcome']);
    }

    /**
     * Restored from the legacy monolith Dashboard's 5-tile stat grid
     * (Total Projects, Published, Images, Tags, Leads with "(+N today)")
     * and the Recent Projects table's Created column.
     */
    public function test_five_tile_stat_grid_fields_are_present(): void
    {
        $project = Project::create([
            'title' => 'Stat Grid Project',
            'slug' => 'stat-grid-project',
            'project_type' => 'kitchen',
            'is_published' => true,
        ]);
        ProjectImage::create([
            'project_id' => $project->id,
            'filename' => 'a.jpg',
            'original_filename' => 'a.jpg',
            'path' => 'projects/1/a.jpg',
        ]);
        Tag::create(['name' => 'Modern', 'slug' => 'modern']);
        ContactSubmission::create([
            'name' => 'Today Lead', 'email' => 'today@example.com',
            'message' => 'hi', 'status' => 'pending', 'created_at' => now(),
        ]);

        $data = $this->getJson('/api/admin/v1/dashboard-stats', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['images']['total']);
        $this->assertSame(1, $data['tags']['total']);
        $this->assertSame(1, $data['leads']['today']);
        $this->assertSame(1, $data['leads']['this_week']);
        $this->assertNotEmpty($data['recent_projects']);
        $this->assertArrayHasKey('created_at', $data['recent_projects'][0]);
        $this->assertNotNull($data['recent_projects'][0]['created_at']);
    }
}
