<?php

namespace App\Services\Seo;

use SsSystems\Platform\Pages\Landing\Contracts\LandingPageContentBuilder;

/**
 * The adapter SsSystems\Platform\Pages\Landing\Http\Concerns\
 * ServesLandingPages is injected with — wraps this site's existing
 * LandingPageContentGenerator (Autopilot's AI-first, proof-gated engine)
 * unchanged, so the kit trait never has to know gsc's content is
 * real-project-photography-gated while every other tenant's is a static
 * template. See docs/CONSOLIDATION-PLAN.md, Kit 0.14.0, and
 * docs/audit-2026-09-27/admin-api-verify.md.
 *
 * `build()` deliberately drops the `slug`/`service`/`city`/`modifier`
 * keys LandingPageContentGenerator::build() still returns (used by its
 * OTHER callers — SeoAutopilotService, the legacy Livewire\Admin\
 * LandingPages screen, AiContentService — which stay untouched): the
 * shared trait now computes those uniformly itself, and its own values
 * win on any key collision in the `array_merge()` it does, so leaving
 * them in this array is harmless but redundant.
 */
class GscLandingPageContentBuilder implements LandingPageContentBuilder
{
    public function __construct(private readonly LandingPageContentGenerator $generator = new LandingPageContentGenerator) {}

    public function services(): array
    {
        return TitleMetaGenerator::SERVICES;
    }

    public function build(array $input): ?array
    {
        return $this->generator->build($input['service'], $input['city'], $input['modifier']);
    }

    /**
     * gsc's Autopilot never publishes a thin page: publish() must find
     * real matched project photography first (see LandingPage::hasProof()
     * and the class's own "no thin pages" rule).
     */
    public function requiresProofToPublish(): bool
    {
        return true;
    }
}
