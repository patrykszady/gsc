<?php

namespace App\Services\Seo\Appliers;

use App\Models\SeoAction;
use App\Services\Seo\ActionApplier;
use Illuminate\Support\Facades\Artisan;

/**
 * Regenerates the GEO answer surface for AI engines — llms.txt and
 * llms-full.txt — from the current config/content. Safe and idempotent: it
 * rebuilds cached files from source, so revert() is a no-op.
 *
 * Deliberately NOT `geo:feed`. The vendor generator writes an (empty) static
 * file to public_path(config('geo.feed.route')) — i.e. public/ai-feed.json —
 * which nginx then serves in place of the rich dynamic feed AiFeedController
 * builds at that same path. The schedule in routes/console.php skips it for
 * the same reason; an autopilot action must not reintroduce it.
 */
class LlmsRegenApplier implements ActionApplier
{
    public function category(): string
    {
        return 'llms_regen';
    }

    public function apply(SeoAction $action): void
    {
        Artisan::call('geo:llms-txt');
        Artisan::call('geo:llms-txt', ['--full' => true]);
    }

    public function revert(SeoAction $action): void
    {
        // No-op: regeneration is idempotent; the files are always rebuilt from source.
    }
}
