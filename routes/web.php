<?php

use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Login;
use App\Livewire\Admin\ProjectForm;
use App\Livewire\Admin\ProjectList;
use App\Livewire\Admin\TagList;
use App\Livewire\Admin\ContactSubmissions;
use App\Livewire\AreaPage;
use App\Livewire\AreasServedPage;
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

Route::get('/projects/{project}', ProjectPage::class)->name('projects.show');

Route::get('/services', ServicesPage::class)->name('services.index');

Route::redirect('/reviews', '/testimonials', 301)->name('reviews.index');
Route::redirect('/contact-us', '/contact', 301);

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
    ->where('page', 'contact|testimonials|projects|about|services|kitchen-remodeling|bathroom-remodeling|home-remodeling')
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
    
    // Contact Submissions / Leads
    Route::get('/leads', ContactSubmissions::class)->name('leads.index');
});
