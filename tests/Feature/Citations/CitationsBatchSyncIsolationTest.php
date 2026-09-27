<?php

namespace Tests\Feature\Citations;

use App\Models\Citation;
use App\Models\Site;
use App\Services\Citations\CitationBatchRunner;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use SsSystems\Platform\Citations\Contracts\CitationRecord;
use Tests\TestCase;

/**
 * The generic proof (mirroring vendor/ss-systems/platform-kit's
 * BatchRunnerTest::test_the_base_query_hook_is_the_only_scoping..., here
 * against gsc's REAL Site/Citation models rather than a bare-PHPUnit
 * fixture) that CitationBatchRunner's/CitationsSync's site scoping lives
 * ONLY in the `$baseQuery`/`$create` Closures bound in those two classes
 * — see CitationBatchRunner's own docblock and CONSOLIDATION-PLAN.md
 * §6's tenancy risk note. Two real tenants, two real sets of rows: a
 * runner/sync bound to one tenant's `Site::current()` context must never
 * read or write the other tenant's directories.
 */
class CitationsBatchSyncIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_batch_runner_eligible_never_crosses_tenants(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = Site::where('slug', 'jpeterson')->firstOrFail();

        Citation::create(['site_id' => $gsc->id, 'slug' => 'gsc-only', 'name' => 'GSC Only', 'tier' => 2, 'status' => CitationRecord::STATUS_PLANNED]);
        Citation::create(['site_id' => $jp->id, 'slug' => 'jp-only', 'name' => 'JP Only', 'tier' => 2, 'status' => CitationRecord::STATUS_PLANNED]);

        $gscSlugs = Tenancy::for($gsc, fn () => app(CitationBatchRunner::class)->eligible()->pluck('slug')->all());
        $jpSlugs = Tenancy::for($jp, fn () => app(CitationBatchRunner::class)->eligible()->pluck('slug')->all());

        $this->assertSame(['gsc-only'], $gscSlugs);
        $this->assertSame(['jp-only'], $jpSlugs);
    }

    public function test_citations_sync_registers_and_sweeps_only_the_bound_tenants_own_rows(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = Site::where('slug', 'jpeterson')->firstOrFail();

        // NOT `fn () => $this->artisan(...)->assertExitCode(0)`: assertExitCode()
        // only records the expectation (Illuminate\Testing\PendingCommand::run()
        // is deferred to __destruct() unless called explicitly), and an arrow
        // function returns that PendingCommand as Tenancy::for()'s own return
        // value — so the last reference, and the __destruct()-triggered actual
        // command run, would land AFTER Tenancy::for()'s finally block has
        // already restored the previous (wrong) tenant. A statement-bodied
        // closure returns null instead, so the PendingCommand's refcount drops
        // to zero, and the command actually runs, while still inside the block.
        Tenancy::for($gsc, function () {
            $this->artisan('citations:sync')->assertExitCode(0);
        });
        Tenancy::for($jp, function () {
            $this->artisan('citations:sync')->assertExitCode(0);
        });

        $expected = count((array) config('citations.directories'));
        $this->assertSame($expected, Citation::where('site_id', $gsc->id)->count());
        $this->assertSame($expected, Citation::where('site_id', $jp->id)->count());

        // Same slug, one row per tenant (the schema's own composite
        // unique(['site_id','slug']) anticipates exactly this).
        Citation::where('site_id', $gsc->id)->where('slug', 'remodelersup')->update(['status' => 'running']);
        Citation::where('site_id', $jp->id)->where('slug', 'remodelersup')->update(['status' => 'running']);

        // Only gsc's sweep runs; jpeterson's identical-slug row must be untouched.
        Tenancy::for($gsc, function () {
            $this->artisan('citations:sync')->assertExitCode(0);
        });

        $this->assertSame('planned', Citation::where('site_id', $gsc->id)->where('slug', 'remodelersup')->value('status'), "gsc's own stale row went back on the board");
        $this->assertSame('running', Citation::where('site_id', $jp->id)->where('slug', 'remodelersup')->value('status'), "jpeterson's identically-slugged row is a different tenant's row and must not move");
    }
}
