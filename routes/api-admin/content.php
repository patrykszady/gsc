<?php

use App\Http\Controllers\Api\Admin\V1\AnalyticsController;
use App\Http\Controllers\Api\Admin\V1\ClientErrorAdminController;
use App\Http\Controllers\Api\Admin\V1\LandingPageController;
use App\Http\Controllers\Api\Admin\V1\SocialMediaController;
use Illuminate\Support\Facades\Route;

// Management-API surface for the 'content' ops domain. Inherits the
// /api/admin/v1 prefix + auth/tenant middleware from the enclosing group in
// routes/api.php. Matches the screens under ss-systems' routes/admin-ops/content.php:
// landing-pages -> LandingPages, social-media -> SocialMediaPosts,
// analytics -> SiteAnalytics, js-errors -> ClientErrors.

// Landing pages (demand-driven /remodeling/ pages).
Route::get('landing-pages', [LandingPageController::class, 'index']);
Route::get('landing-pages/services', [LandingPageController::class, 'services']);
Route::post('landing-pages', [LandingPageController::class, 'store']);
Route::patch('landing-pages/{landingPage}/publish', [LandingPageController::class, 'publish']);
Route::patch('landing-pages/{landingPage}/unpublish', [LandingPageController::class, 'unpublish']);
Route::delete('landing-pages/{landingPage}', [LandingPageController::class, 'destroy']);

// Social media — one aggregate GET (the source screen never paginated any
// of its lists), a URL-roster save, and the (never-verify-live) post action.
Route::get('social-media', [SocialMediaController::class, 'index']);
Route::put('social-media/urls', [SocialMediaController::class, 'saveUrls']);
Route::post('social-media/post', [SocialMediaController::class, 'post']);

// Analytics — filtered/paginated event rows, plus a compact aggregate
// (headline stats + top pages + trend series) so chart data never ships as
// raw rows.
Route::get('analytics/events', [AnalyticsController::class, 'events']);
Route::get('analytics/summary', [AnalyticsController::class, 'summary']);

// JS errors (aggregated client_errors — see the public beacon controller,
// App\Http\Controllers\ClientErrorController, for how these rows are made).
Route::get('js-errors', [ClientErrorAdminController::class, 'index']);
Route::get('js-errors/summary', [ClientErrorAdminController::class, 'summary']);
Route::patch('js-errors/{clientError}/resolve', [ClientErrorAdminController::class, 'resolve']);
Route::patch('js-errors/{clientError}/unresolve', [ClientErrorAdminController::class, 'unresolve']);
Route::patch('js-errors/resolve-all', [ClientErrorAdminController::class, 'resolveAll']);
Route::delete('js-errors/{clientError}', [ClientErrorAdminController::class, 'destroy']);
