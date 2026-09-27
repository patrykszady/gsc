<?php

namespace App\Support\Citations;

use SsSystems\Platform\Citations\Contracts\LinkTarget;

/**
 * gsc's own "what counts as us" for the citations link check — moved
 * unchanged from `App\Console\Commands\CitationsControl`'s `check`
 * sub-action into its own class so it can implement the kit's
 * `Citations\Contracts\LinkTarget` (see `SsSystems\Platform\Citations\
 * LinkCheckRunner`, kit 0.13.0).
 */
class SiteLinkTarget implements LinkTarget
{
    public function domain(): string
    {
        return preg_replace('#^https?://(www\.)?#', '', rtrim((string) config('app.url'), '/')) ?: 'gs.construction';
    }

    public function siteUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** @return list<string> */
    public function businessNames(): array
    {
        return array_filter([(string) config('brand.display_name'), (string) config('brand.name')]);
    }
}
