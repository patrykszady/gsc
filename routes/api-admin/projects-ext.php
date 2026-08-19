<?php

// gsc-only project extras for pixel parity with the legacy admin:
// timelapses (+frames), before/after pairs, per-image tag assignment.
// Filled by the ProjectForm parity port; inherits /api/admin/v1 prefix +
// auth/tenant middleware.
//
// Ported from app/Livewire/Admin/ProjectForm.php — see
// TimelapseController/BeforeAfterController/ProjectImageController::syncTags
// docblocks for how each maps back to that Livewire component's methods.

use App\Http\Controllers\Api\Admin\V1\BeforeAfterController;
use App\Http\Controllers\Api\Admin\V1\ProjectImageController;
use App\Http\Controllers\Api\Admin\V1\TimelapseController;
use Illuminate\Support\Facades\Route;

// Bulk image tag assignment (ProjectForm's "Assign Tag" card / Image Tags
// card) — full replace, see ProjectImageController::syncTags docblock.
Route::put('projects/{project}/images/{image}/tags', [ProjectImageController::class, 'syncTags']);

// Timelapses ----------------------------------------------------------------
Route::get('projects/{project}/timelapses', [TimelapseController::class, 'index']);
Route::post('projects/{project}/timelapses', [TimelapseController::class, 'store']);
Route::put('projects/{project}/timelapses/{timelapse}', [TimelapseController::class, 'update']);
Route::delete('projects/{project}/timelapses/{timelapse}', [TimelapseController::class, 'destroy']);

Route::post('projects/{project}/timelapses/{timelapse}/frames', [TimelapseController::class, 'storeFrame']);
Route::post('projects/{project}/timelapses/{timelapse}/frames/from-gallery', [TimelapseController::class, 'storeFrameFromGallery']);
Route::post('projects/{project}/timelapses/{timelapse}/frames/reorder', [TimelapseController::class, 'reorderFrames']);
Route::put('projects/{project}/timelapses/{timelapse}/frames/{frame}', [TimelapseController::class, 'updateFrame']);
Route::delete('projects/{project}/timelapses/{timelapse}/frames/{frame}', [TimelapseController::class, 'destroyFrame']);

// Before / Afters -------------------------------------------------------------
Route::get('projects/{project}/before-afters', [BeforeAfterController::class, 'index']);
Route::post('projects/{project}/before-afters', [BeforeAfterController::class, 'store']);
Route::put('projects/{project}/before-afters/{beforeAfter}', [BeforeAfterController::class, 'update']);
Route::delete('projects/{project}/before-afters/{beforeAfter}', [BeforeAfterController::class, 'destroy']);

Route::post('projects/{project}/before-afters/{beforeAfter}/{slot}', [BeforeAfterController::class, 'uploadSlot'])
    ->where('slot', 'before|after');
Route::post('projects/{project}/before-afters/{beforeAfter}/{slot}/from-gallery', [BeforeAfterController::class, 'fillSlotFromGallery'])
    ->where('slot', 'before|after');
