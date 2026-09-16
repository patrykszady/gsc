<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\SeoAction;
use App\Services\Seo\SeoAutopilotService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * run()/apply()/revert() all reach the real Autopilot appliers (Google
 * Indexing API pings, llms.txt regen, page creation, title/meta rewrites via
 * an LLM) — per the porting task's hard safety rules, none of that may ever
 * execute for real here. run() mocks the Artisan facade outright; apply()/
 * revert() swap in a mock SeoAutopilotService; Http::fake() is a blanket
 * backstop in both. skip() is a plain status-column write with no external
 * effect, so it runs for real against the sqlite test database.
 */
class SeoAutopilotControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
        Http::fake();
    }

    private function makeAction(array $overrides = []): SeoAction
    {
        return SeoAction::create(array_merge([
            'fingerprint' => 'fp-'.str()->random(8),
            'source' => 'striking_distance',
            'category' => 'title_meta',
            'risk' => SeoAction::RISK_SAFE,
            'target_url' => 'https://gs.construction/remodeling/example',
            'title' => 'Rewrite title for /example',
            'hypothesis' => 'Better CTR from a sharper title.',
            'payload' => ['new_title' => 'New Title'],
            'priority' => 10.0,
            'impact_score' => 5.0,
            'status' => SeoAction::STATUS_PROPOSED,
        ], $overrides));
    }

    public function test_stats_carry_prior_window_values_for_every_kpi_chevron(): void
    {
        $now = Carbon::parse('2026-09-15 12:00:00');
        Carbon::setTestNow($now);
        $cutoff = $now->copy()->subDays(7); // 2026-09-08

        // Open bucket: A existed and is still open; B existed at the cutoff but has
        // since been applied; C did not exist yet at the cutoff.
        $this->makeAction(['fingerprint' => 'a', 'impact_score' => 5, 'created_at' => $now->copy()->subDays(10)]);
        $this->makeAction(['fingerprint' => 'b', 'impact_score' => 7, 'created_at' => $now->copy()->subDays(10),
            'status' => SeoAction::STATUS_APPLIED, 'applied_at' => $now->copy()->subDays(3)]);
        $this->makeAction(['fingerprint' => 'c', 'impact_score' => 3, 'created_at' => $now->copy()->subDays(3)]);

        // Applied bucket: D applied before the cutoff and never reverted; E was
        // applied before the cutoff but reverted after it; F applied after the cutoff.
        $this->makeAction(['fingerprint' => 'd', 'status' => SeoAction::STATUS_APPLIED, 'applied_at' => $now->copy()->subDays(10)]);
        $this->makeAction(['fingerprint' => 'e', 'status' => SeoAction::STATUS_REVERTED, 'applied_at' => $now->copy()->subDays(10), 'reverted_at' => $now->copy()->subDays(3)]);
        $this->makeAction(['fingerprint' => 'f', 'status' => SeoAction::STATUS_APPLIED, 'applied_at' => $now->copy()->subDays(3)]);

        // Outcome buckets: G was measured before the cutoff, H only just now.
        $this->makeAction(['fingerprint' => 'g', 'status' => SeoAction::STATUS_APPLIED, 'outcome' => SeoAction::OUTCOME_WORKED, 'measured_at' => $now->copy()->subDays(10)]);
        $this->makeAction(['fingerprint' => 'h', 'status' => SeoAction::STATUS_APPLIED, 'outcome' => SeoAction::OUTCOME_WORKED, 'measured_at' => $now->copy()->subDays(3)]);

        $stats = $this->getJson('/api/admin/v1/seo/autopilot?tab=open', $this->adminApiHeaders())->assertOk()->json('stats');

        // Point-in-time gauges: 'prev' is the same gauge as of 7 days ago.
        $this->assertSame(2, $stats['open'], 'A and C are open now');
        $this->assertSame(2, $stats['open_prev'], 'A and B were open as of the cutoff');
        $this->assertEquals(8.0, $stats['est_uplift'], 'A(5) + C(3)');
        $this->assertEquals(12.0, $stats['est_uplift_prev'], 'A(5) + B(7) as of the cutoff');

        // 'applied' counts every row currently in the applied status: B, D, F, G, H
        // (E moved to reverted, A/C never applied).
        $this->assertSame(5, $stats['applied']);
        // 'applied_prev' only cares about applied_at/reverted_at: D (applied before
        // the cutoff, never reverted) and E (applied before the cutoff, reverted
        // after it) were "applied as of the cutoff"; B and F applied after it; G
        // and H never got an applied_at at all in this fixture.
        $this->assertSame(2, $stats['applied_prev']);

        $this->assertSame(2, $stats['worked']);
        $this->assertSame(1, $stats['worked_prev'], 'only G was measured by the cutoff');
        $this->assertSame(0, $stats['regressed']);
        $this->assertSame(0, $stats['regressed_prev']);
        $this->assertSame(0, $stats['no_effect']);
        $this->assertSame(0, $stats['no_effect_prev']);

        Carbon::setTestNow();
    }

    public function test_index_lists_open_actions_with_stats_and_weights(): void
    {
        $this->makeAction();

        $data = $this->getJson('/api/admin/v1/seo/autopilot?tab=open', $this->adminApiHeaders())
            ->assertOk()
            ->json();

        $this->assertCount(1, $data['data']);
        $this->assertSame('title_meta', $data['data'][0]['category']);
        $this->assertArrayHasKey('total', $data['meta']);
        $this->assertSame(1, $data['stats']['open']);
        $this->assertNotEmpty($data['weights']);
    }

    public function test_run_never_executes_the_real_autopilot_command(): void
    {
        Artisan::shouldReceive('call')->once()->with('seo:autopilot', ['--max' => 25])->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn("Autopilot cycle complete.\n");

        $data = $this->postJson('/api/admin/v1/seo/autopilot/run', [], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString('Autopilot cycle complete.', $data['message']);
    }

    public function test_apply_delegates_to_the_service_without_running_real_appliers(): void
    {
        $action = $this->makeAction();

        $this->mock(SeoAutopilotService::class, function (MockInterface $mock) use ($action): void {
            $mock->shouldReceive('applyOne')
                ->once()
                ->withArgs(fn (SeoAction $a) => $a->is($action))
                ->andReturnUsing(function (SeoAction $a) {
                    $a->update(['status' => SeoAction::STATUS_APPLIED, 'applied_at' => now()]);

                    return true;
                });
        });

        $data = $this->postJson("/api/admin/v1/seo/autopilot/actions/{$action->id}/apply", [], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString('Applied', $data['message']);
        $this->assertSame(SeoAction::STATUS_APPLIED, $data['action']['status']);
    }

    public function test_apply_404s_for_an_action_that_is_not_open(): void
    {
        $action = $this->makeAction(['status' => SeoAction::STATUS_APPLIED, 'applied_at' => now()]);

        $this->postJson("/api/admin/v1/seo/autopilot/actions/{$action->id}/apply", [], $this->adminApiHeaders())
            ->assertNotFound();
    }

    public function test_revert_delegates_to_the_service(): void
    {
        $action = $this->makeAction(['status' => SeoAction::STATUS_APPLIED, 'applied_at' => now(), 'payload' => ['previous_title' => 'Old']]);

        $this->mock(SeoAutopilotService::class, function (MockInterface $mock) use ($action): void {
            $mock->shouldReceive('revert')
                ->once()
                ->withArgs(fn (SeoAction $a) => $a->is($action))
                ->andReturnUsing(function (SeoAction $a): void {
                    $a->update(['status' => SeoAction::STATUS_REVERTED, 'reverted_at' => now()]);
                });
        });

        $data = $this->postJson("/api/admin/v1/seo/autopilot/actions/{$action->id}/revert", [], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(SeoAction::STATUS_REVERTED, $data['action']['status']);
    }

    public function test_skip_writes_the_status_column_directly_and_is_safe_to_run_for_real(): void
    {
        $action = $this->makeAction();

        $data = $this->postJson("/api/admin/v1/seo/autopilot/actions/{$action->id}/skip", [], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(SeoAction::STATUS_SKIPPED, $data['action']['status']);
        $this->assertSame(SeoAction::STATUS_SKIPPED, $action->fresh()->status);
    }

    public function test_skip_404s_for_an_unknown_action(): void
    {
        $this->postJson('/api/admin/v1/seo/autopilot/actions/999999/skip', [], $this->adminApiHeaders())
            ->assertNotFound();
    }
}
