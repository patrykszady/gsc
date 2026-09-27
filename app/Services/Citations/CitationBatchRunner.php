<?php

namespace App\Services\Citations;

use App\Models\Citation;
use App\Models\Site;
use SsSystems\Platform\Citations\BatchRunner;
use SsSystems\Platform\Citations\Contracts\CitationSession;

/**
 * gsc's site-scoped binding of the kit's Citations\BatchRunner
 * (citations-batch-sync, 2026-09-27 — moved to vendor/ss-systems/
 * platform-kit's Citations\BatchRunner, which this class was ported to
 * verbatim from). The only thing left here is the one seam the kit
 * cannot know about: every query runs through `$baseQuery`, scoped to
 * `Site::current()`, so an automatic run never reads or writes another
 * tenant's directories (see BatchRunner's own docblock, and
 * `CitationsBatchSyncIsolationTest` for the two-Site proof). `ELIGIBLE`,
 * `progress()`, `isActive()`, `progressState()` and `runOne()` are all
 * inherited unchanged.
 */
class CitationBatchRunner extends BatchRunner
{
    public function __construct(CitationSession $sessions)
    {
        parent::__construct($sessions, fn () => Citation::query()->where('site_id', Site::current()?->id));
    }
}
