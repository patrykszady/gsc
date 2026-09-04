<?php

// Management-API surface for the citation builder (business profiles and
// directory listings). Inherits the /api/admin/v1 prefix + auth/tenant
// middleware from the enclosing group in routes/api.php.

use App\Http\Controllers\Api\Admin\V1\CitationsController;
use Illuminate\Support\Facades\Route;

Route::prefix('citations')->group(function () {
    Route::get('/', [CitationsController::class, 'index']);
    Route::get('payload', [CitationsController::class, 'payload']);
    Route::post('session/poll', [CitationsController::class, 'poll']);
    Route::post('session/stop', [CitationsController::class, 'stop']);
    Route::post('{slug}/start', [CitationsController::class, 'start']);
    Route::post('{slug}/resume', [CitationsController::class, 'resume']);
    Route::patch('{slug}', [CitationsController::class, 'update']);
    Route::get('{slug}/screenshots/{file}', [CitationsController::class, 'screenshot']);
});
