<?php

use App\Http\Controllers\Api\Admin\V1\AreaController;
use App\Http\Controllers\Api\Admin\V1\DashboardStatsController;
use App\Http\Controllers\Api\Admin\V1\LeadController;
use App\Http\Controllers\Api\Admin\V1\PingController;
use App\Http\Controllers\Api\Admin\V1\ProjectController;
use App\Http\Controllers\Api\Admin\V1\ProjectImageController;
use App\Http\Controllers\Api\Admin\V1\TagController;
use App\Http\Controllers\Api\Admin\V1\TestimonialController;
use App\Http\Controllers\Api\LeadFilterSyncController;
use App\Http\Middleware\AuthenticateLeadFilterSync;
use Illuminate\Support\Facades\Route;

// Cross-site spam-filter sync (gsc <-> jpeterson): a dedicated, narrow
// endpoint — NOT part of the ss-systems management API above, and not
// guarded by admin.api.auth. The only caller is the peer site's own
// SyncLeadFilterToPeer job, authenticated with its own shared bearer token
// (AuthenticateLeadFilterSync, referenced by class here rather than a
// bootstrap/app.php alias — this route file is the only thing that needs
// to know about it). Lives at the top level of this file (the 'api' route
// group is already stateless/CSRF-exempt) rather than inside the admin/v1
// group below, which is ss-systems' surface.
Route::post('lead-filters/sync', LeadFilterSyncController::class)
    ->middleware(['throttle:30,1', AuthenticateLeadFilterSync::class]);

/*
|--------------------------------------------------------------------------
| Management API — /api/admin/v1
|--------------------------------------------------------------------------
|
| The ONLY caller is ss-systems, talking to this site over HTTP with a
| bearer token (admin.api.auth — AuthenticateAdminApi). Stateless: no
| Sanctum, no session, just the token. ss-systems is the source of truth
| for the ADMIN UI; this site is the source of truth for its own content.
|
| Project's route key is "slug" (public pages use pretty URLs), but this
| API is called by id — {project}/{image}/{tag}/{testimonial}/{lead} are
| deliberately plain ints here, resolved with findOrFail() in the
| controllers, rather than relying on implicit route-model binding.
*/
// admin.api.tenant (gsc-only) pins Site::current() to the gsc row so
// BelongsToSite's global scope filters every model query — the copied
// controllers stay tenancy-blind.
// 6000/min: this API's real gate is the bearer token — one trusted
// server-to-server caller (ss-systems), whose admin screens fan out many
// requests per page. 60/min throttled a normally-browsing operator (429s
// in the central admin); the limit now exists only as an abuse ceiling.
// name('api.admin.v1.') is not decoration: apiResource() derives route names
// from the resource, so 'testimonials' here generated a bare
// testimonials.index that collided with the PUBLIC /testimonials route of the
// same name. Laravel tolerates duplicate names until route:cache, which then
// refuses to serialize — taking the whole deploy down — and before that,
// route('testimonials.index') silently resolved to whichever registered last.
Route::prefix('admin/v1')->name('api.admin.v1.')->middleware(['throttle:6000,1', 'admin.api.auth', 'admin.api.tenant'])->group(function () {
    Route::get('ping', PingController::class);
    Route::get('dashboard-stats', DashboardStatsController::class);

    // {project} stays a plain int, resolved via Project::findOrFail() in the
    // controller — NOT implicit route-model binding, which would try
    // Project's route key ("slug", for the public /portfolio pages) against
    // the numeric id ss-systems actually sends.
    // Reviews linkable from the project form — the other end of the
    // testimonial<->project pivot the review form writes. gsc-only
    // ('testimonial-projects' capability); jpeterson has no pivot.
    Route::get('projects/linkable-testimonials', [\App\Http\Controllers\Api\Admin\V1\ProjectController::class, 'linkableTestimonials']);
    Route::get('projects/types', [ProjectController::class, 'types']);
    Route::apiResource('projects', ProjectController::class);

    Route::get('projects/{project}/images', [ProjectImageController::class, 'index']);
    Route::post('projects/{project}/images', [ProjectImageController::class, 'store']);
    Route::post('projects/{project}/images/reorder', [ProjectImageController::class, 'reorder']);
    Route::put('projects/{project}/images/{image}', [ProjectImageController::class, 'update']);
    Route::delete('projects/{project}/images/{image}', [ProjectImageController::class, 'destroy']);

    Route::apiResource('tags', TagController::class)->except(['show']);

    Route::post('blog-posts/{post}/regenerate', [\App\Http\Controllers\Api\Admin\V1\BlogPostController::class, 'regenerate']);
    Route::post('projects/{project}/blog-post', [\App\Http\Controllers\Api\Admin\V1\BlogPostController::class, 'generateForProject']);
    Route::get('projects/{project}/blog-post', [\App\Http\Controllers\Api\Admin\V1\BlogPostController::class, 'statusForProject']);
    Route::apiResource('blog-posts', \App\Http\Controllers\Api\Admin\V1\BlogPostController::class)->except(['store']);

    Route::get('testimonials/filters', [TestimonialController::class, 'filters']);
    Route::apiResource('testimonials', TestimonialController::class);

    // gsc-only: jpeterson's ping omits the "areas" capability.
    Route::apiResource('areas', AreaController::class);

    Route::get('leads/stats', [LeadController::class, 'stats']);
    Route::get('leads', [LeadController::class, 'index']);
    Route::get('leads/{lead}', [LeadController::class, 'show']);
    Route::patch('leads/{lead}/status', [LeadController::class, 'updateStatus']);
    Route::delete('leads/{lead}', [LeadController::class, 'destroy']);

    // Ops domains (SEO, social, platforms, analytics, errors), one file per
    // area — see the matching screens in ss-systems' routes/admin-ops/.
    require __DIR__.'/api-admin/content.php';
    require __DIR__.'/api-admin/seo.php';
    require __DIR__.'/api-admin/platforms.php';
    require __DIR__.'/api-admin/projects-ext.php';
    require __DIR__.'/api-admin/areas-ext.php';
});
