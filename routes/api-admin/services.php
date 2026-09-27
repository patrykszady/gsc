<?php

use App\Http\Controllers\Api\Admin\V1\ServiceController;
use Illuminate\Support\Facades\Route;
use SsSystems\Platform\Http\Admin\CapabilityRegistry;

// The company's services — the vocabulary behind the project form's
// "Project Type" (see App\Models\Service). 'service-content' (Service::
// SECTIONS page copy) rides the same resource, no route of its own.
//
// reorder is registered ABOVE the apiResource, or the resource's
// services/{service} wildcard would swallow "services/reorder" and bind
// the literal string — the same trap documented in areas.php.
CapabilityRegistry::declare('services', 'service-content');
Route::post('services/reorder', [ServiceController::class, 'reorder']);
// On-demand content generation for one service's own page — same shape as
// POST areas/{area}/generate.
Route::post('services/{service}/generate', [ServiceController::class, 'generate'])->whereNumber('service');
Route::apiResource('services', ServiceController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
