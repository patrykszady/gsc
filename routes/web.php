<?php

use App\Http\Controllers\AiFeedController;
use App\Http\Controllers\GeoAnswersController;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\PlatformsSettings;
use App\Livewire\Admin\Login;
use App\Livewire\Admin\ProjectForm;
use App\Livewire\Admin\ProjectList;
use App\Livewire\Admin\TagList;
use App\Livewire\Admin\ContactSubmissions;
use App\Livewire\Admin\TestimonialForm;
use App\Livewire\Admin\TestimonialList;
use App\Livewire\Admin\AreaList;
use App\Livewire\Admin\AreaForm;
use App\Livewire\AreaPage;
use App\Livewire\AreasServedPage;
use App\Livewire\CompareCompetitorPage;
use App\Livewire\CompareIndexPage;
use App\Livewire\ProjectImagePage;
use App\Livewire\ProjectPage;
use App\Livewire\ServiceAreaIndex;
use App\Livewire\ServicePage;
use App\Livewire\ServicesPage;
use App\Livewire\TestimonialPage;
use App\Livewire\ZipCodePage;
use App\Models\ShortLink;
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

// Short links (used in Instagram captions, etc.)
Route::get('/s/{code}', function (string $code) {
    $link = ShortLink::where('code', $code)->firstOrFail();
    $link->recordClick();

    return redirect()->away($link->url, 301);
})->where('code', '[A-Za-z0-9]{4,8}')->name('short-link');

Route::get('/', function () {
    SeoService::home();
    return view('home');
})->name('home');

// AI / GEO: structured feed for ChatGPT, Perplexity, Google AI Overviews, Claude.
Route::get('/ai-feed.json', AiFeedController::class)->name('ai-feed');
Route::get('/geo/answers.json', GeoAnswersController::class)->name('geo.answers');

// Reviews (canonical). Old /testimonials URLs 301 → /reviews for SEO/GEO.
// "reviews" matches schema.org/Review, has ~10× search volume vs "testimonials",
// and aligns with how AI assistants phrase queries.
Route::get('/reviews', function () {
    SeoService::testimonials();
    return view('testimonials');
})->name('reviews.index');

Route::get('/reviews/{testimonial}', TestimonialPage::class)->name('reviews.show');

// 301 redirects from legacy /testimonials URLs (preserves link equity).
Route::redirect('/testimonials', '/reviews', 301)->name('testimonials.index');
Route::get('/testimonials/{testimonial}', function (string $testimonial) {
    return redirect("/reviews/{$testimonial}", 301);
})->name('testimonials.show');

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

Route::redirect('/contact-us', '/contact', 301);

// Legacy root-level service URLs → new /services/* pattern
Route::redirect('/bathroom-remodeling', '/services/bathroom-remodeling', 301);
Route::redirect('/kitchen-remodeling', '/services/kitchen-remodeling', 301);
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
    ->where('service', 'kitchen-remodeling|bathroom-remodeling|home-remodeling|basement-remodeling|home-additions')
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
    ->where('service', 'kitchen-remodeling|bathroom-remodeling|home-remodeling|basement-remodeling|home-additions')
    ->name('locations.service');

// Areas Served (canonical)
Route::get('/areas-served', AreasServedPage::class)->name('areas.index');
Route::get('/areas-served/{area}', AreaPage::class)
    ->defaults('page', 'home')
    ->name('areas.show');
Route::get('/areas-served/{area}/{page}', AreaPage::class)
    ->where('page', 'contact|testimonials|projects|about|services')
    ->name('areas.page');

// Area-specific service pages (e.g., /areas-served/arlington-heights/services/kitchen-remodeling)
Route::get('/areas-served/{area}/services/{service}', AreaPage::class)
    ->defaults('page', 'service')
    ->where('service', 'kitchen-remodeling|bathroom-remodeling|home-remodeling|basement-remodeling|home-additions')
    ->name('areas.service');

// 301 redirects from old short slugs to keyword-rich canonical URLs
Route::get('/areas-served/{area}/services/kitchens', function (string $area) {
    return redirect("/areas-served/{$area}/services/kitchen-remodeling", 301);
});
Route::get('/areas-served/{area}/services/bathrooms', function (string $area) {
    return redirect("/areas-served/{$area}/services/bathroom-remodeling", 301);
});

// ZIP-code service-area landing pages (drives long-tail local search)
Route::get('/service-area', ServiceAreaIndex::class)->name('service-area.index');
Route::get('/service-area/{zip}', ZipCodePage::class)
    ->where('zip', '\d{5}')
    ->name('service-area.show');

// Redirects from old area-level service URLs
Route::get('/areas-served/{area}/kitchen-remodeling', function (string $area) {
    return redirect("/areas-served/{$area}/services/kitchen-remodeling", 301);
});
Route::get('/areas-served/{area}/bathroom-remodeling', function (string $area) {
    return redirect("/areas-served/{$area}/services/bathroom-remodeling", 301);
});
Route::get('/areas-served/{area}/home-remodeling', function (string $area) {
    return redirect("/areas-served/{$area}/services/home-remodeling", 301);
});

// Service landing pages (canonical keyword-rich URLs)
Route::get('/services/kitchen-remodeling', ServicePage::class)
    ->defaults('service', 'kitchen-remodeling')
    ->name('services.kitchen');
Route::get('/services/bathroom-remodeling', ServicePage::class)
    ->defaults('service', 'bathroom-remodeling')
    ->name('services.bathroom');
Route::get('/services/home-remodeling', ServicePage::class)
    ->defaults('service', 'home-remodeling')
    ->name('services.home');
Route::get('/services/basement-remodeling', ServicePage::class)
    ->defaults('service', 'basement-remodeling')
    ->name('services.basement');
Route::get('/services/home-additions', ServicePage::class)
    ->defaults('service', 'home-additions')
    ->name('services.additions');

// 301 redirects from old short service URLs
Route::redirect('/services/kitchens', '/services/kitchen-remodeling', 301);
Route::redirect('/services/bathrooms', '/services/bathroom-remodeling', 301);
Route::redirect('/services/basements', '/services/basement-remodeling', 301);
Route::redirect('/services/basement-finishing', '/services/basement-remodeling', 301);
Route::redirect('/services/additions', '/services/home-additions', 301);
Route::redirect('/services/room-additions', '/services/home-additions', 301);
Route::redirect('/basement-remodeling', '/services/basement-remodeling', 301);
Route::redirect('/basement-finishing', '/services/basement-remodeling', 301);
Route::redirect('/home-additions', '/services/home-additions', 301);
Route::redirect('/additions', '/services/home-additions', 301);

// Comparison / "alternative to" landing pages
Route::get('/compare', CompareIndexPage::class)->name('compare.index');
Route::get('/compare/{slug}', CompareCompetitorPage::class)
    ->where('slug', '[a-z0-9\-]+')
    ->name('compare.show');

// Admin auth
Route::get('/admin/login', Login::class)->name('admin.login')->middleware(['guest', 'noindex']);
Route::post('/admin/logout', function () {
    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect()->route('admin.login');
})->name('admin.logout')->middleware('noindex');

// Admin routes (protected by auth)
Route::middleware(['auth', 'noindex'])->prefix('admin')->name('admin.')->group(function () {
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

    // Service Areas
    Route::get('/areas', AreaList::class)->name('areas.index');
    Route::get('/areas/create', AreaForm::class)->name('areas.create');
    Route::get('/areas/{area}/edit', AreaForm::class)->name('areas.edit');

    // Social Media
    Route::get('/social-media', \App\Livewire\Admin\SocialMediaPosts::class)->name('social-media.index');

    // Platforms (Google Business Profile, Yelp, etc.)
    Route::get('/platforms', PlatformsSettings::class)->name('platforms.index');

    // SEO weekly reports dashboard
    Route::get('/seo-reports/{report?}', \App\Livewire\Admin\SeoReports::class)->name('seo-reports.index');
    Route::get('/platforms/gbp/callback', function (\Illuminate\Http\Request $request) {
        $code = $request->query('code');
        if (! $code) {
            session()->flash('platforms-error', 'Authorization cancelled or failed — no code returned.');
            return redirect()->route('admin.platforms.index');
        }

        $service = app(\App\Services\GoogleBusinessProfileService::class);
        $result = $service->exchangeCodeAndStore($code, route('admin.platforms.gbp-callback'));

        if ($result['success']) {
            session()->flash('platforms-success', 'Google Business Profile connected successfully!');
        } else {
            session()->flash('platforms-error', 'OAuth failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        return redirect()->route('admin.platforms.index');
    })->name('platforms.gbp-callback');
});
