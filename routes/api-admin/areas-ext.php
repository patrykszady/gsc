<?php

// gsc-only service-area extras for pixel parity: coverage-map data,
// candidate towns, town resolution (OSM geocode). See AreaMapController.
//
// Registered under areas-map/* rather than nested under areas/* —
// Route::apiResource('areas', AreaController::class) in routes/api.php
// already claims GET areas/{area}, which would swallow a sibling
// "areas/map" segment before it ever reached this controller. Inherits the
// /api/admin/v1 prefix + auth/tenant middleware from the enclosing group.

use App\Http\Controllers\Api\Admin\V1\AreaMapController;
use Illuminate\Support\Facades\Route;

Route::prefix('areas-map')->group(function () {
    Route::get('/', [AreaMapController::class, 'map']);
    Route::get('candidates', [AreaMapController::class, 'candidates']);
    Route::post('resolve-town', [AreaMapController::class, 'resolveTown']);
    Route::post('from-map', [AreaMapController::class, 'createFromMap']);
});
