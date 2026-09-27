<?php

namespace App\Http\Controllers;

use SsSystems\Platform\Seo\Faq\Contracts\FaqCatalog;
use SsSystems\Platform\Seo\Faq\Http\Concerns\BuildsFaqPageFeed;

/**
 * Machine-readable Q&A feed for AI engines (ChatGPT, Perplexity, Google AI
 * Overviews, Claude). Served at /geo/answers.json and linked from llms.txt.
 *
 * Source: config/geo-answers.php (curated, short-form answers ready for
 * direct citation by generative search systems), through
 * App\Support\Seo\Faq\ConfigFaqCatalog. The envelope itself is
 * BuildsFaqPageFeed (kit 0.13.0) — this class supplies only the four
 * config('geo-answers.meta.*') reads whose fallback default is this site's
 * own (see the trait's docblock for why those can't move to the kit).
 */
class GeoAnswersController extends Controller
{
    use BuildsFaqPageFeed;

    public function __construct(private readonly FaqCatalog $catalog) {}

    protected function faqCatalog(): FaqCatalog
    {
        return $this->catalog;
    }

    protected function faqBusinessName(): string
    {
        return (string) config('geo-answers.meta.business', 'GS Construction');
    }

    protected function faqPhone(): ?string
    {
        return config('geo-answers.meta.phone');
    }

    protected function faqEmail(): ?string
    {
        return config('geo-answers.meta.email');
    }

    protected function faqAreaServed(): ?string
    {
        return config('geo-answers.meta.service_area');
    }
}
