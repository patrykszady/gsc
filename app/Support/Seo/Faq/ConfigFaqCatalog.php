<?php

namespace App\Support\Seo\Faq;

use SsSystems\Platform\Seo\Faq\Bank\FaqBankCatalog;
use SsSystems\Platform\Seo\Faq\Bank\FaqBankStore;
use SsSystems\Platform\Seo\Faq\Contracts\FaqCatalog;

/**
 * gs.construction's /geo/answers.json list: the fixed config/geo-answers.php
 * answers, unchanged and first — no Service/Area FAQ merge (that's
 * jpeterson-design's own addition, kept on its own adapter) — followed by
 * the FAQ bank (kit 0.24.0, 2026-11-10): the answers `seo:faq-bank`
 * derived from this site's own pages, each carrying the page it came from
 * as `url` and its kind as its topic. FaqBankCatalog::merge() keeps the
 * hand-written answer for a question both lists hold and caps the whole
 * list. Bound in AppServiceProvider; a deployment whose bank tables are
 * not migrated yet serves the hand-written list alone rather than a 500.
 */
final class ConfigFaqCatalog implements FaqCatalog
{
    public function __construct(private readonly FaqBankStore $bank) {}

    public function items(): array
    {
        $handWritten = array_values((array) config('geo-answers.answers', []));

        return $this->bank->exists() ? FaqBankCatalog::merge($handWritten, $this->bank) : $handWritten;
    }

    public function publisherExtras(): array
    {
        return [
            'knowsLanguage' => config('geo-answers.meta.languages', ['English']),
        ];
    }
}
