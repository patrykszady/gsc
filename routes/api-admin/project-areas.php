<?php

// project-areas — one real job split into areas (Kitchen, Mudroom,
// Basement…), each with its own category, description and photos. Most of
// the surface rides the existing 'projects' resource: Project::toApiArray()'s
// "areas"/"images[].area_id" keys and ProjectController@update's "areas"
// handling (see that controller). The routes below are the bits that don't:
// assigning photos to (or back out of) an area in bulk, a new area, and
// each area's own cover photo + description draft.
//
// Ported from jpeterson-design.com (2026-09-30) via ss-platform-kit 0.16.0 —
// see that kit's docs/PROJECT-AREAS.md and the project-areas admin-API
// contract. ss-systems shows the areas UI only when this capability is in
// the ping response.
//
// Required from routes/api.php right after projects-ext.php, inside the
// admin/v1 group, so throttle + admin.api.auth + admin.api.tenant apply the
// same as every other admin-API route.

use App\Http\Controllers\Api\Admin\V1\ProjectAreaController;
use Illuminate\Support\Facades\Route;
use SsSystems\Platform\Http\Admin\CapabilityRegistry;

CapabilityRegistry::declare('project-areas');

// A new area, optionally holding selected photos straight away —
// SsSystems\Platform\Projects\Http\Concerns\ServesProjectAreas::storeArea().
Route::post('projects/{project}/areas', [ProjectAreaController::class, 'storeArea']);

// An area's own cover photo — ProjectArea::cover(), the admin-chosen
// image.id this area leads with. See ServesProjectAreas::updateAreaCover().
Route::put('projects/{project}/areas/{area}/cover', [ProjectAreaController::class, 'updateAreaCover'])->whereNumber('area');

// Per-area description draft (Gemini vision), the area-scoped twin of a
// project-level details job. Gated by the existing 'ai-project-details'
// capability (not declared on gsc at all today — see the contract
// addendum, "no new one"); the endpoint works even while the admin button
// stays hidden until that capability is declared, which is a separate
// decision.
Route::post('projects/{project}/areas/{area}/generate-description', [ProjectAreaController::class, 'generateAreaDescription'])->whereNumber('area');

// Bulk-assign (or, with a null area_id, unassign) this project's own
// photos to one of its areas — ServesProjectAreas::assignImagesToArea().
Route::post('projects/{project}/images/area', [ProjectAreaController::class, 'assignImagesToArea']);
