<?php

namespace App\Services\Seo\Appliers;

use App\Models\SeoAction;
use App\Services\Seo\ActionApplier;
use Illuminate\Support\Facades\Artisan;

/**
 * Regenerates the GEO answer surface for AI engines — llms.txt, llms-full.txt
 * and the AI product feed — from the current config/content. Safe and
 * idempotent: it rebuilds cached files from source, so revert() is a no-op.
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
        Artisan::call('geo:feed');
    }

    public function revert(SeoAction $action): void
    {
        // No-op: regeneration is idempotent; the files are always rebuilt from source.
    }
}
