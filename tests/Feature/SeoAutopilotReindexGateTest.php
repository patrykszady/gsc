<?php

namespace Tests\Feature;

use App\Models\GscCoverageState;
use App\Models\SeoAction;
use App\Services\Seo\SeoAutopilotService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Reindex actions only ever reach IndexNow (Bing/Yandex/Seznam) — see
 * App\Services\Seo\Appliers\ReindexApplier — never Google's Indexing API,
 * which honours just JobPosting/BroadcastEvent pages. ss.systems now
 * presses "Request indexing" in Search Console for this site daily, which
 * makes the autopilot's own reindex proposals redundant (SEO review,
 * 2026-10-10). seo.autopilot.reindex_actions gates every path that
 * synthesizes one, defaulting off; seo:autopilot-retire-reindex clears
 * whatever was already open under the old default.
 */
class SeoAutopilotReindexGateTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** A coverage row that would have produced a 'reindex' action before the gate. */
    private function problemCoverageRow(): GscCoverageState
    {
        return GscCoverageState::create([
            'url' => 'https://gs.construction/areas-served/some-orphaned-town',
            'verdict' => 'FAIL',
            'coverage_state' => 'Discovered - currently not indexed',
            'inspected_at' => now(),
        ]);
    }

    public function test_no_reindex_actions_are_synthesized_with_the_flag_off_by_default(): void
    {
        $this->problemCoverageRow();

        app(SeoAutopilotService::class)->synthesize();

        $this->assertSame(0, SeoAction::where('category', 'reindex')->count());
    }

    public function test_reindex_synthesis_still_works_when_the_flag_is_explicitly_on(): void
    {
        config(['seo.autopilot.reindex_actions' => true]);
        $row = $this->problemCoverageRow();

        app(SeoAutopilotService::class)->synthesize();

        $action = SeoAction::where('category', 'reindex')->first();
        $this->assertNotNull($action, 'turning the flag on must restore the old behavior');
        $this->assertSame($row->url, $action->target_url);
        $this->assertSame(SeoAction::STATUS_PROPOSED, $action->status);
    }

    public function test_the_retire_command_skips_only_pending_reindex_actions(): void
    {
        $pendingReindexA = SeoAction::create([
            'fingerprint' => 'fp-reindex-a', 'source' => 'coverage_error', 'category' => 'reindex',
            'risk' => SeoAction::RISK_SAFE, 'target_url' => 'https://gs.construction/a',
            'title' => 'Reindex: /a', 'status' => SeoAction::STATUS_PROPOSED,
        ]);
        $pendingReindexB = SeoAction::create([
            'fingerprint' => 'fp-reindex-b', 'source' => 'intel', 'category' => 'reindex',
            'risk' => SeoAction::RISK_SAFE, 'target_url' => 'https://gs.construction/b',
            'title' => 'Reindex: /b', 'status' => SeoAction::STATUS_PROPOSED,
        ]);
        $appliedReindex = SeoAction::create([
            'fingerprint' => 'fp-reindex-c', 'source' => 'coverage_error', 'category' => 'reindex',
            'risk' => SeoAction::RISK_SAFE, 'target_url' => 'https://gs.construction/c',
            'title' => 'Reindex: /c', 'status' => SeoAction::STATUS_APPLIED, 'applied_at' => now(),
        ]);
        $pendingTitleMeta = SeoAction::create([
            'fingerprint' => 'fp-title-meta', 'source' => 'striking_distance', 'category' => 'title_meta',
            'risk' => SeoAction::RISK_SAFE, 'target_url' => 'https://gs.construction/d',
            'title' => 'Rewrite title: /d', 'status' => SeoAction::STATUS_PROPOSED,
        ]);

        $this->artisan('seo:autopilot-retire-reindex')
            ->expectsOutputToContain('Skipped 2 pending reindex action(s).')
            ->assertSuccessful();

        $this->assertSame(SeoAction::STATUS_SKIPPED, $pendingReindexA->fresh()->status);
        $this->assertSame('Superseded by ss.systems indexing requests (2026-10-10)', $pendingReindexA->fresh()->notes);
        $this->assertSame(SeoAction::STATUS_SKIPPED, $pendingReindexB->fresh()->status);

        // Untouched: already-applied reindex history, and a pending action in a different category.
        $this->assertSame(SeoAction::STATUS_APPLIED, $appliedReindex->fresh()->status);
        $this->assertSame(SeoAction::STATUS_PROPOSED, $pendingTitleMeta->fresh()->status);
    }

    public function test_the_retire_commands_dry_run_changes_nothing(): void
    {
        $pendingReindex = SeoAction::create([
            'fingerprint' => 'fp-reindex-dry', 'source' => 'coverage_error', 'category' => 'reindex',
            'risk' => SeoAction::RISK_SAFE, 'target_url' => 'https://gs.construction/e',
            'title' => 'Reindex: /e', 'status' => SeoAction::STATUS_PROPOSED,
        ]);

        $this->artisan('seo:autopilot-retire-reindex', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] Would skip 1 pending reindex action(s).')
            ->assertSuccessful();

        $this->assertSame(SeoAction::STATUS_PROPOSED, $pendingReindex->fresh()->status);
        $this->assertNull($pendingReindex->fresh()->notes);
    }
}
