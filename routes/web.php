<?php

use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Login;
use App\Livewire\Admin\ProjectForm;
use App\Livewire\Admin\ProjectList;
use App\Livewire\Admin\TagList;
use App\Livewire\Admin\ContactSubmissions;
use App\Livewire\Admin\TestimonialForm;
use App\Livewire\Admin\TestimonialList;
use App\Livewire\AreaPage;
use App\Livewire\AreasServedPage;
use App\Livewire\ProjectImagePage;
use App\Livewire\ProjectPage;
use App\Livewire\ServicePage;
use App\Livewire\ServicesPage;
use App\Livewire\TestimonialPage;
use App\Services\SeoService;
use Illuminate\Support\Facades\Route;

// Note: robots.txt is served as a static file from public/robots.txt
// This ensures fastest response and works even if PHP is down.

// IndexNow key verification file
Route::get('/{key}.txt', function (string $key) {
    $indexNowKey = config('indexnow.key');
    
    if (! $indexNowKey || $key !== $indexNowKey) {
        abort(404);
    }
    
    return response($indexNowKey, 200)->header('Content-Type', 'text/plain');
})->where('key', '[a-z0-9\-]{8,128}');

Route::get('/', function () {
    SeoService::home();
    return view('home');
})->name('home');

Route::get('/testimonials', function () {
    SeoService::testimonials();
    return view('testimonials');
})->name('testimonials.index');

Route::get('/testimonials/{testimonial}', TestimonialPage::class)->name('testimonials.show');

Route::get('/about', function () {
    SeoService::about();
    return view('about');
})->name('about');

Route::get('/contact', function () {
    SeoService::contact();
    return view('contact');
})->name('contact');

Route::get('/projects', function () {
    SeoService::projects(null, request('type'));
    return view('projects');
})->name('projects.index');

Route::get('/projects/{type}', function (string $type) {
    $typeMap = [
        'kitchens' => 'kitchen',
        'bathrooms' => 'bathroom',
        'home-remodeling' => 'home-remodel',
    ];

    if (!isset($typeMap[$type])) {
        abort(404);
    }

    request()->merge(['type' => $typeMap[$type]]);
    SeoService::projects(null, $typeMap[$type]);
    return view('projects');
})->where('type', 'kitchens|bathrooms|home-remodeling')
  ->name('projects.type');

// API endpoint for background image preloading
Route::get('/api/project-images', function () {
    $images = \App\Models\ProjectImage::all()
        ->flatMap(function ($image) {
            $urls = [];
            // Get medium size (most commonly used)
            $url = $image->getWebpThumbnailUrl('medium') ?? $image->getThumbnailUrl('medium');
            if ($url) $urls[] = $url;
            // Get thumb for blur placeholders
            $thumb = $image->getWebpThumbnailUrl('thumb') ?? $image->getThumbnailUrl('thumb');
            if ($thumb) $urls[] = $thumb;
            return $urls;
        })
        ->unique()
        ->values();
    
    return response()->json($images)
        ->header('Cache-Control', 'public, max-age=3600'); // Cache for 1 hour
})->name('api.project-images');

Route::get('/projects/{project}', ProjectPage::class)->name('projects.show');
Route::get('/projects/{project}/photos/{image}', ProjectImagePage::class)->name('projects.image');

Route::get('/services', ServicesPage::class)->name('services.index');

Route::redirect('/reviews', '/testimonials', 301)->name('reviews.index');
Route::redirect('/contact-us', '/contact', 301);

// Legacy root-level service URLs → new /services/* pattern
Route::redirect('/bathroom-remodeling', '/services/bathrooms', 301);
Route::redirect('/kitchen-remodeling', '/services/kitchens', 301);
Route::redirect('/home-remodeling', '/services/home-remodeling', 301);

// /areas alias (same content as /areas-served, noindex + canonical handled in component)
Route::get('/areas', AreasServedPage::class)->name('areas.alias.index');
Route::get('/areas/{area}', AreaPage::class)
    ->defaults('page', 'home')
    ->name('areas.alias.show');
Route::get('/areas/{area}/{page}', AreaPage::class)
    ->where('page', 'contact|testimonials|projects|about|services')
    ->name('areas.alias.page');
Route::get('/areas/{area}/services/{service}', AreaPage::class)
    ->defaults('page', 'service')
    ->where('service', 'kitchens|bathrooms|home-remodeling')
    ->name('areas.alias.service');

// Locations alias (keep canonical on /areas-served)
Route::get('/locations', AreasServedPage::class)->name('locations.index');
Route::get('/locations/{area}', AreaPage::class)
    ->defaults('page', 'home')
    ->name('locations.show');
Route::get('/locations/{area}/{page}', AreaPage::class)
    ->where('page', 'contact|testimonials|projects|about|services')
    ->name('locations.page');
Route::get('/locations/{area}/services/{service}', AreaPage::class)
    ->defaults('page', 'service')
    ->where('service', 'kitchens|bathrooms|home-remodeling')
    ->name('locations.service');

// Areas Served (canonical)
Route::get('/areas-served', AreasServedPage::class)->name('areas.index');
Route::get('/areas-served/{area}', AreaPage::class)
    ->defaults('page', 'home')
    ->name('areas.show');
Route::get('/areas-served/{area}/{page}', AreaPage::class)
    ->where('page', 'contact|testimonials|projects|about|services')
    ->name('areas.page');

// Area-specific service pages (e.g., /areas-served/arlington-heights/services/kitchens)
Route::get('/areas-served/{area}/services/{service}', AreaPage::class)
    ->defaults('page', 'service')
    ->where('service', 'kitchens|bathrooms|home-remodeling')
    ->name('areas.service');

// Redirects from old area-service URLs to new pattern
Route::get('/areas-served/{area}/kitchen-remodeling', function (string $area) {
    return redirect("/areas-served/{$area}/services/kitchens", 301);
});
Route::get('/areas-served/{area}/bathroom-remodeling', function (string $area) {
    return redirect("/areas-served/{$area}/services/bathrooms", 301);
});
Route::get('/areas-served/{area}/home-remodeling', function (string $area) {
    return redirect("/areas-served/{$area}/services/home-remodeling", 301);
});

// Redirects from long service names under /services/ path
Route::get('/areas-served/{area}/services/kitchen-remodeling', function (string $area) {
    return redirect("/areas-served/{$area}/services/kitchens", 301);
});
Route::get('/areas-served/{area}/services/bathroom-remodeling', function (string $area) {
    return redirect("/areas-served/{$area}/services/bathrooms", 301);
});

// Service landing pages (short URLs)
Route::get('/services/kitchens', ServicePage::class)
    ->defaults('service', 'kitchen-remodeling')
    ->name('services.kitchen');
Route::get('/services/bathrooms', ServicePage::class)
    ->defaults('service', 'bathroom-remodeling')
    ->name('services.bathroom');
Route::get('/services/home-remodeling', ServicePage::class)
    ->defaults('service', 'home-remodeling')
    ->name('services.home');

// Redirects from old service URLs
Route::redirect('/services/kitchen-remodeling', '/services/kitchens', 301);
Route::redirect('/services/bathroom-remodeling', '/services/bathrooms', 301);

// Admin auth
Route::get('/admin/login', Login::class)->name('admin.login')->middleware('guest');
Route::post('/admin/logout', function () {
    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect()->route('admin.login');
})->name('admin.logout');

// Admin routes (protected by auth)
Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');
    
    // Projects
    Route::get('/projects', ProjectList::class)->name('projects.index');
    Route::get('/projects/create', ProjectForm::class)->name('projects.create');
    Route::get('/projects/{project}/edit', ProjectForm::class)->name('projects.edit');
    
    // Tags
    Route::get('/tags', TagList::class)->name('tags.index');
    
    // Contact Submissions / Leads
    Route::get('/leads', ContactSubmissions::class)->name('leads.index');

    // Testimonials / Reviews
    Route::get('/testimonials', TestimonialList::class)->name('testimonials.index');
    Route::get('/testimonials/create', TestimonialForm::class)->name('testimonials.create');
    Route::get('/testimonials/{testimonial}/edit', TestimonialForm::class)->name('testimonials.edit');

    // Social Media
    Route::get('/social-media', \App\Livewire\Admin\SocialMediaPosts::class)->name('social-media.index');
});
