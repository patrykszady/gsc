<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Services\Seo\GscLandingPageContentBuilder;
use Illuminate\Support\Facades\Artisan;
use SsSystems\Platform\Pages\Landing\Contracts\LandingPageContentBuilder;
use SsSystems\Platform\Pages\Landing\Http\Concerns\ServesLandingPages;

/**
 * Management API for gsc's Livewire\Admin\LandingPages screen — demand-driven
 * /remodeling/ pages. Generation and publish stay proof-gated exactly as the
 * original component enforced; only the transport changed.
 *
 * Ported onto the kit's shared ServesLandingPages trait (kit 0.13.0,
 * docs/CONSOLIDATION-PLAN.md — see docs/audit-2026-09-27/admin-api-verify.md
 * for why the naive "6-method contract" framing needed the extra hooks
 * below). This site is the 'refuse' + 'requires proof' reference every
 * other tenant's controller is diffed against.
 */
class LandingPageController extends Controller
{
    use ServesLandingPages;

    public function __construct(private readonly LandingPageContentBuilder $contentBuilder = new GscLandingPageContentBuilder) {}

    protected function landingPageModel(): string
    {
        return LandingPage::class;
    }

    protected function contentBuilder(): LandingPageContentBuilder
    {
        return $this->contentBuilder;
    }

    /** gsc 422s a duplicate deterministic slug rather than auto-suffixing — see ContentOpsControllerTest::test_generate_rejects_a_duplicate_slug_with_a_field_error. */
    protected function onSlugCollision(): string
    {
        return 'refuse';
    }

    /** Sitemap can be regenerated manually; don't block the response on it — same as the original controller/Livewire component. */
    protected function afterPublishToggle(): void
    {
        try {
            Artisan::call('sitemap:generate');
        } catch (\Throwable) {
            // Intentionally silent — same as the original Livewire component.
        }
    }
}
