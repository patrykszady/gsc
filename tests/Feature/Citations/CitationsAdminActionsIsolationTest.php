<?php

namespace Tests\Feature\Citations;

use App\Models\Citation;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use SsSystems\Platform\Citations\CitationsAdminActions;
use SsSystems\Platform\Citations\Contracts\CitationRecord;
use Tests\TestCase;

/**
 * The generic proof (mirroring CitationsBatchSyncIsolationTest's own, here
 * against the kit's `Citations\CitationsAdminActions` itself rather than
 * `CitationBatchRunner`/`Sync` directly) that resolving the service under
 * one tenant's `Site::current()` context never reads or writes another
 * tenant's directories — see AppServiceProvider's own binding for why it
 * is `bind`, never `singleton`.
 */
class CitationsAdminActionsIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_index_never_crosses_tenants(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = Site::where('slug', 'jpeterson')->firstOrFail();

        Citation::create(['site_id' => $gsc->id, 'slug' => 'gsc-only', 'name' => 'GSC Only', 'tier' => 2, 'status' => CitationRecord::STATUS_LIVE]);
        Citation::create(['site_id' => $jp->id, 'slug' => 'jp-only', 'name' => 'JP Only', 'tier' => 2, 'status' => CitationRecord::STATUS_LIVE]);

        // index() also syncs every configured directory in (ensureSynced()),
        // so each tenant's board is the full roster PLUS its own seeded row
        // — the isolation proof is that each tenant's own row shows up on
        // its own board only, never the other tenant's.
        $gscSlugs = Tenancy::for($gsc, fn () => collect(app(CitationsAdminActions::class)->index()['citations'])->pluck('slug')->all());
        $jpSlugs = Tenancy::for($jp, fn () => collect(app(CitationsAdminActions::class)->index()['citations'])->pluck('slug')->all());

        $this->assertContains('gsc-only', $gscSlugs);
        $this->assertNotContains('jp-only', $gscSlugs);
        $this->assertContains('jp-only', $jpSlugs);
        $this->assertNotContains('gsc-only', $jpSlugs);
    }

    public function test_find_and_update_never_cross_tenants(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = Site::where('slug', 'jpeterson')->firstOrFail();

        Citation::create(['site_id' => $gsc->id, 'slug' => 'remodelersup', 'name' => 'RemodelerSup', 'tier' => 2, 'status' => CitationRecord::STATUS_PLANNED]);
        Citation::create(['site_id' => $jp->id, 'slug' => 'remodelersup', 'name' => 'RemodelerSup', 'tier' => 2, 'status' => CitationRecord::STATUS_PLANNED]);

        // Same slug, one row per tenant — updating gsc's must never touch jpeterson's.
        Tenancy::for($gsc, function () {
            app(CitationsAdminActions::class)->update('remodelersup', ['status' => CitationRecord::STATUS_LIVE]);
        });

        $this->assertSame(CitationRecord::STATUS_LIVE, Citation::where('site_id', $gsc->id)->where('slug', 'remodelersup')->value('status'));
        $this->assertSame(CitationRecord::STATUS_PLANNED, Citation::where('site_id', $jp->id)->where('slug', 'remodelersup')->value('status'), "jpeterson's identically-slugged row is a different tenant's row and must not move");
    }
}
