<?php

// Management-API surface for the 'seo' ops domain — filled in by its port.
// Inherits the /api/admin/v1 prefix + auth/tenant middleware from the
// enclosing group in routes/api.php.
//
// Mirrors app/Livewire/Admin/{SeoReports,SeoAutopilotPanel,SeoOverridesPanel,
// GscErrors}.php. See those Livewire classes' controller counterparts under
// Api\Admin\V1 for what each endpoint computes.

use App\Http\Controllers\Api\Admin\V1\GscErrorController;
use App\Http\Controllers\Api\Admin\V1\SeoAutopilotController;
use App\Http\Controllers\Api\Admin\V1\SeoOverrideController;
use App\Http\Controllers\Api\Admin\V1\SeoReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('seo')->group(function () {
    // SeoReports: generated markdown reports (list, single report + body,
    // regenerate-on-demand) plus the page's live "snapshot" data (search
    // performance, health, clarity, GEO, AI traffic, the embedded GSC-errors
    // summary, and the impressions diagnostic).
    Route::get('reports', [SeoReportController::class, 'index']);
    Route::get('reports/{report}', [SeoReportController::class, 'show']);
    Route::post('reports/{report}/regenerate', [SeoReportController::class, 'regenerate']);
    Route::get('snapshot', [SeoReportController::class, 'snapshot']);
    Route::post('snapshot/refresh', [SeoReportController::class, 'refreshSnapshot']);

    // SeoAutopilotPanel: the scored action ledger + learned weights.
    Route::get('autopilot', [SeoAutopilotController::class, 'index']);
    Route::post('autopilot/run', [SeoAutopilotController::class, 'run']);
    Route::post('autopilot/actions/{action}/apply', [SeoAutopilotController::class, 'apply']);
    Route::post('autopilot/actions/{action}/revert', [SeoAutopilotController::class, 'revert']);
    Route::post('autopilot/actions/{action}/skip', [SeoAutopilotController::class, 'skip']);

    // SeoOverridesPanel: per-record title/description/author/image/
    // canonical_url/robots overrides. {type} is an allowlist (project, area,
    // testimonial) — see SeoOverrideController::TYPES.
    Route::get('overrides/{type}/{id}', [SeoOverrideController::class, 'show']);
    Route::put('overrides/{type}/{id}', [SeoOverrideController::class, 'update']);
    Route::delete('overrides/{type}/{id}', [SeoOverrideController::class, 'destroy']);

    // GscErrors: URL Inspection coverage states, rich-result/enhancement
    // issues, the last reindex report, and CSV export.
    Route::get('gsc-errors', [GscErrorController::class, 'index']);
    Route::post('gsc-errors/prune-retired', [GscErrorController::class, 'pruneRetired']);
    Route::post('gsc-errors/refresh', [GscErrorController::class, 'refresh']);
    Route::get('gsc-errors/export', [GscErrorController::class, 'export']);
    // Search Console, read and write: the Page-indexing breakdown the
    // Console shows (from the sweep, the Googlebot 404 tracker and the robots
    // rules), a Console export to inspect, one URL inspected on demand, and
    // the property's sitemaps (list / submit / delete).
    Route::get('gsc-errors/indexing', [GscErrorController::class, 'indexing']);
    Route::post('gsc-errors/import', [GscErrorController::class, 'importConsoleExport']);
    Route::post('gsc-errors/inspect', [GscErrorController::class, 'inspect']);
    Route::get('gsc-errors/sitemaps', [GscErrorController::class, 'sitemaps']);
    Route::post('gsc-errors/sitemaps', [GscErrorController::class, 'submitSitemap']);
    Route::delete('gsc-errors/sitemaps', [GscErrorController::class, 'deleteSitemap']);
});
