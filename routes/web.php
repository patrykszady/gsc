<?php

use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Login;
use App\Livewire\Admin\ProjectForm;
use App\Livewire\Admin\ProjectList;
use App\Livewire\Admin\TagList;
use App\Models\AreaServed;
use Illuminate\Support\Facades\Route;

// Dynamic robots.txt using APP_URL
Route::get('/robots.txt', function () {
    $content = "User-agent: *\n";
    $content .= "Disallow: /admin/\n";
    $content .= "Disallow: /api/\n\n";
    $content .= "Sitemap: " . config('app.url') . "/sitemap.xml\n";
    
    return response($content, 200)->header('Content-Type', 'text/plain');
});

Route::get('/', function () {
    return view('home');
})->name('home');

Route::get('/areas/{area:slug}', function (AreaServed $area) {
    return view('home', ['area' => $area]);
})->name('area.home');

Route::view('/testimonials', 'testimonials')->name('testimonials.index');
Route::view('/about', 'about')->name('about');
Route::view('/contact', 'contact')->name('contact');
Route::view('/projects', 'projects')->name('projects.index');
Route::get('/areas/{area:slug}/testimonials', function (AreaServed $area) {
    return view('testimonials', ['area' => $area]);
})->name('area.testimonials');
Route::get('/areas/{area:slug}/about', function (AreaServed $area) {
    return view('about', ['area' => $area]);
})->name('area.about');
Route::get('/areas/{area:slug}/contact', function (AreaServed $area) {
    return view('contact', ['area' => $area]);
})->name('area.contact');
Route::get('/areas/{area:slug}/projects', function (AreaServed $area) {
    return view('projects', ['area' => $area]);
})->name('area.projects');
Route::redirect('/reviews', '/testimonials', 301)->name('reviews.index');

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
