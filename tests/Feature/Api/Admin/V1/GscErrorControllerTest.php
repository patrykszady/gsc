<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\RunGscInspectBulkJob;
use App\Models\GscCoverageState;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

class GscErrorControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    public function test_index_returns_rows_stats_enhancements_and_reindex_report(): void
    {
        GscCoverageState::create([
            'url' => 'https://gs.construction/remodeling/broken',
            'verdict' => 'FAIL',
            'coverage_state' => 'Not indexed',
            'inspected_at' => now(),
        ]);
        GscCoverageState::create([
            'url' => 'https://gs.construction/remodeling/fine',
            'verdict' => 'PASS',
            'coverage_state' => 'Submitted and indexed',
            'inspected_at' => now(),
        ]);

        $data = $this->getJson('/api/admin/v1/seo/gsc-errors', $this->adminApiHeaders())
            ->assertOk()
            ->json();

        // Default scope is "problems", so only the FAIL row shows.
        $this->assertCount(1, $data['data']);
        $this->assertSame('Not indexed', $data['data'][0]['coverage_state']);
        $this->assertSame(2, $data['stats']['tracked']);
        $this->assertSame(1, $data['stats']['problem']);
        $this->assertArrayHasKey('available', $data['enhancements']);
        $this->assertArrayHasKey('available', $data['reindex_report']);
    }

    public function test_index_scope_all_includes_passing_rows(): void
    {
        GscCoverageState::create(['url' => 'https://gs.construction/a', 'verdict' => 'PASS', 'inspected_at' => now()]);

        $data = $this->getJson('/api/admin/v1/seo/gsc-errors?scope=all', $this->adminApiHeaders())
            ->assertOk()
            ->json();

        $this->assertCount(1, $data['data']);
    }

    public function test_prune_retired_deletes_nothing_when_no_tracked_urls_left_the_sitemap(): void
    {
        // No GscCoverageState rows exist in this test, so regardless of
        // whether public/sitemap.xml is present on this box, there is
        // nothing tracked-but-retired to delete.
        $data = $this->postJson('/api/admin/v1/seo/gsc-errors/prune-retired', [], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $data['deleted']);
    }

    public function test_prune_retired_deletes_only_urls_that_left_a_readable_sitemap(): void
    {
        if (! is_file(public_path('sitemap.xml'))) {
            $this->markTestSkipped('No public/sitemap.xml on this box to exercise the readable-sitemap branch.');
        }

        GscCoverageState::create(['url' => 'https://gs.construction/this-url-is-definitely-not-in-the-sitemap-xyz', 'verdict' => 'FAIL', 'inspected_at' => now()]);

        $data = $this->postJson('/api/admin/v1/seo/gsc-errors/prune-retired', [], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['deleted']);
        $this->assertStringContainsString('Pruned 1 retired URL', $data['message']);
        $this->assertSame(0, GscCoverageState::query()->count());
    }

    public function test_refresh_queues_the_bulk_inspection_job_without_running_it(): void
    {
        Queue::fake();

        $this->postJson('/api/admin/v1/seo/gsc-errors/refresh', [], $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.message', 'Queued full sitemap inspection in background. Data will update as the job writes new results.');

        Queue::assertPushed(RunGscInspectBulkJob::class);
    }

    public function test_export_streams_a_csv_of_the_filtered_rows(): void
    {
        GscCoverageState::create(['url' => 'https://gs.construction/x', 'verdict' => 'FAIL', 'coverage_state' => 'Server error', 'inspected_at' => now()]);

        $response = $this->get('/api/admin/v1/seo/gsc-errors/export', $this->adminApiHeaders());

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('gs.construction/x', $response->streamedContent());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/v1/seo/gsc-errors')->assertUnauthorized();
    }
}
