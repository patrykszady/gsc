<?php

// Management-API surface for the 'pages' ping domain — gs.construction's
// own built-in public pages (home, about, contact, services + each
// service page, areas-served index). See PageController's docblock for
// why title/description round-trip through SeoPathOverride rather than
// the polymorphic `seo` table SeoOverrideController writes.
//
// 'pages/types' is registered ABOVE the {page} routes, or the wildcard
// would swallow "pages/types" and try to resolve "types" as an id — same
// trap documented in services.php/areas.php.

use App\Http\Controllers\Api\Admin\V1\PageController;
use Illuminate\Support\Facades\Route;

Route::get('pages/types', [PageController::class, 'types']);
Route::get('pages', [PageController::class, 'index']);
Route::get('pages/{page}', [PageController::class, 'show'])->whereNumber('page');
Route::put('pages/{page}', [PageController::class, 'update'])->whereNumber('page');
Route::post('pages', [PageController::class, 'store']);
Route::delete('pages/{page}', [PageController::class, 'destroy'])->whereNumber('page');
