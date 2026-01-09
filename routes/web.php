<?php

use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Login;
use App\Livewire\Admin\ProjectForm;
use App\Livewire\Admin\ProjectList;
use App\Livewire\Admin\TagList;
use App\Livewire\AreaPage;
use App\Livewire\AreasServedPage;
use App\Livewire\ServicePage;
use App\Livewire\ServicesPage;
use App\Livewire\TestimonialPage;
use App\Services\SeoService;
use Illuminate\Support\Facades\Route;

// Dynamic robots.txt using APP_URL
Route::get('/robots.txt', function () {
    $baseUrl = config('app.url');
    
    $content = <<<ROBOTS
# GS Construction & Remodeling
# https://gs.construction

# Allow all crawlers
User-agent: *

# Disallow admin and internal paths
Disallow: /admin/
Disallow: /livewire/
Disallow: /log-viewer/
Disallow: /storage/

# Disallow query parameters that create duplicate content
Disallow: /*?*

# Allow important query parameters for filtering (override above)
Allow: /projects?type=
Allow: /areas-served?

# Crawl-delay for polite crawling (optional, respected by some bots)
Crawl-delay: 1

# Sitemaps
Sitemap: {$baseUrl}/sitemap.xml

# AI Training Bots - Opt out of AI training
User-agent: GPTBot
Disallow: /

User-agent: ChatGPT-User
Disallow: /

User-agent: CCBot
Disallow: /

User-agent: anthropic-ai
Disallow: /

User-agent: Claude-Web
Disallow: /

User-agent: Google-Extended
Disallow: /

User-agent: FacebookBot
Disallow: /

User-agent: Bytespider
Disallow: /

User-agent: Amazonbot
Disallow: /

# Host directive (helps some search engines)
Host: {$baseUrl}
ROBOTS;

    return response($content, 200)->header('Content-Type', 'text/plain');
});

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

Route::get('/services', ServicesPage::class)->name('services.index');

Route::redirect('/reviews', '/testimonials', 301)->name('reviews.index');

// Legacy /areas/ redirects (correct path is /areas-served/)
Route::redirect('/areas', '/areas-served', 301);
Route::get('/areas/{area}', function (string $area) {
    return redirect("/areas-served/{$area}", 301);
});
Route::get('/areas/{area}/{page}', function (string $area, string $page) {
    return redirect("/areas-served/{$area}/{$page}", 301);
})->where('page', 'contact|testimonials|projects|about|services');

// Areas Served
Route::get('/areas-served', AreasServedPage::class)->name('areas.index');
Route::get('/areas-served/{area}', AreaPage::class)
    ->defaults('page', 'home')
    ->name('areas.show');
Route::get('/areas-served/{area}/{page}', AreaPage::class)
    ->where('page', 'contact|testimonials|projects|about|services')
    ->name('areas.page');

// Service landing pages
Route::get('/services/kitchen-remodeling', ServicePage::class)
    ->defaults('service', 'kitchen-remodeling')
    ->name('services.kitchen');
Route::get('/services/bathroom-remodeling', ServicePage::class)
    ->defaults('service', 'bathroom-remodeling')
    ->name('services.bathroom');
Route::get('/services/home-remodeling', ServicePage::class)
    ->defaults('service', 'home-remodeling')
    ->name('services.home');

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
});
