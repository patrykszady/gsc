<?php

namespace App\Services\Seo\Appliers;

use App\Models\SeoAction;
use App\Services\Seo\ActionApplier;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Nudges Googlebot to re-crawl a coverage-problem URL by resubmitting it to
 * IndexNow + warming cache (seo:reindex-problem-pages --urls=…).
 *
 * Inherently idempotent and non-destructive — a re-crawl ping cannot be "undone"
 * and re-pinging is harmless — so revert() is a documented no-op.
 */
class ReindexApplier implements ActionApplier
{
    public function category(): string
    {
        return 'reindex';
    }

    public function apply(SeoAction $action): void
    {
        $url = $action->target_url ?: ($action->payload['url'] ?? null);
        if (! $url) {
            throw new RuntimeException("reindex action #{$action->id} has no target_url.");
        }

        Artisan::call('seo:reindex-problem-pages', ['--urls' => [$url]]);
    }

    public function revert(SeoAction $action): void
    {
        // No-op: a crawl-request ping is not reversible and is harmless to leave.
    }
}
