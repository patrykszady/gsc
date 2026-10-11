<?php

namespace App\Console\Commands;

use App\Models\SeoAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-time cleanup for the seo.autopilot.reindex_actions flag flip
 * (config/seo.php): reindex actions only ever reach IndexNow
 * (Bing/Yandex/Seznam) — see App\Services\Seo\Appliers\ReindexApplier —
 * never Google's Indexing API, which honours just JobPosting/BroadcastEvent
 * pages. ss.systems now presses "Request indexing" in Search Console for
 * this site daily, so every reindex action still sitting open is redundant.
 *
 * Retires them with the model's existing skip path (the same STATUS_SKIPPED
 * write the admin panel's skip() action and SeoAutopilotController::skip()
 * use), scoped to the 'reindex' category and stamped with a note so the
 * ledger shows why they closed. Applied/reverted/failed history is left
 * alone — only pending (STATUS_PROPOSED) reindex actions are touched.
 */
class SeoAutopilotRetireReindex extends Command
{
    private const NOTE = 'Superseded by ss.systems indexing requests (2026-10-10)';

    protected $signature = 'seo:autopilot-retire-reindex
        {--dry-run : Report how many pending reindex actions would be skipped without changing anything}';

    protected $description = 'Mark every pending "reindex" autopilot action skipped — superseded by ss.systems requesting indexing in Search Console daily.';

    public function handle(): int
    {
        $pending = SeoAction::open()->where('category', 'reindex');

        if ($this->option('dry-run')) {
            $count = $pending->count();
            $this->info("[DRY RUN] Would skip {$count} pending reindex action(s).");

            return self::SUCCESS;
        }

        $count = $pending->update([
            'status' => SeoAction::STATUS_SKIPPED,
            'notes' => self::NOTE,
        ]);

        $this->info("Skipped {$count} pending reindex action(s).");

        if ($count > 0) {
            Log::info('seo:autopilot-retire-reindex skipped pending reindex actions', ['count' => $count]);
        }

        return self::SUCCESS;
    }
}
