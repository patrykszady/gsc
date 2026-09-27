<?php

namespace App\Support\Seo\Faq;

use SsSystems\Platform\Seo\Faq\Contracts\FaqCatalog;

/**
 * gs.construction's /geo/answers.json list: the fixed config/geo-answers.php
 * answers alone — no Service/Area FAQ merge (that's jpeterson-design's own
 * addition, kept on its own adapter). Bound in AppServiceProvider.
 */
final class ConfigFaqCatalog implements FaqCatalog
{
    public function items(): array
    {
        return (array) config('geo-answers.answers', []);
    }

    public function publisherExtras(): array
    {
        return [
            'knowsLanguage' => config('geo-answers.meta.languages', ['English']),
        ];
    }
}
