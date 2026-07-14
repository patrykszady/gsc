<?php

use App\Http\Controllers\AiFeedController;
use App\Http\Controllers\ClientErrorController;
use App\Http\Controllers\GeoAnswersController;
use App\Http\Controllers\TrackEventController;
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
use App\Livewire\JobsPage;
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

// Gone-for-good URLs. Returning 410 (instead of 404) tells Google to deindex faster.
// Real FAQ page: curated Q&A (shared with the /geo/answers.json GEO feed),
// rendered with FAQ schema for rich results + AI-engine citation.
Route::get('/faq', fn () => view('faq'))->name('faq');

// AI / GEO: structured feed for ChatGPT, Perplexity, Google AI Overviews, Claude.
Route::get('/ai-feed.json', AiFeedController::class)->name('ai-feed');
Route::get('/geo/answers.json', GeoAnswersController::class)->name('geo.answers');

// First-party analytics ingest (phone/email/form/CTA events). Public, rate-limited.
Route::post('/track', TrackEventController::class)->name('track-event');

// Front-end JavaScript error beacon (window.onerror / unhandledrejection).
// Throttled to absorb error storms without flooding the log channel.
Route::post('/client-error', ClientErrorController::class)
    ->middleware('throttle:30,1')
    ->name('client-error');

// Reviews (canonical). Old /testimonials URLs 301 → /reviews for SEO/GEO.
// "reviews" matches schema.org/Review, has ~10× search volume vs "testimonials",
// and aligns with how AI assistants phrase queries.
Route::get('/reviews', function () {
    SeoService::testimonials();
    return view('testimonials');
})->name('reviews.index');

Route::get('/reviews/{testimonial}', TestimonialPage::class)->name('reviews.show');

// Shareable review shortlink. Text or email gs.construction/review to happy
// customers and it drops them straight onto the Google write-review form —
// review volume + recency is the single biggest local-pack ranking lever.
Route::get('/review', function () {
    $placeId = (string) config('services.google.business_profile.place_id');
    $target = $placeId !== ''
        ? 'https://search.google.com/local/writereview?placeid=' . urlencode($placeId)
        : 'https://www.google.com/maps/search/?api=1&query=' . urlencode('GS Construction Remodeling');

    return redirect()->away($target, 302);
})->name('review.write');

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

// Careers & trade partnerships (email-only inquiry form).
Route::get('/jobs', JobsPage::class)->name('jobs.index');
Route::redirect('/careers', '/jobs', 301);
Route::redirect('/job', '/jobs', 301);
Route::redirect('/employment', '/jobs', 301);
Route::redirect('/partners', '/jobs', 301);
Route::redirect('/partnership', '/jobs', 301);
Route::redirect('/partnerships', '/jobs', 301);

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
// Scope {image} to its parent {project} so photo slugs that are duplicated
// across projects resolve to the image under THIS project (an unscoped bind
// picks the first same-slug image globally and 404s on the project mismatch).
Route::get('/projects/{project}/photos/{image:slug}', ProjectImagePage::class)
    ->scopeBindings()
    ->name('projects.image');

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

// Per-municipality lead service line replacement guides. Data comes from the
// official-source research stored in storage/app/lead-service-lines.json
// (App\Support\LeadLineInfo); areas without verified official info render
// generic Illinois-law content and are noindexed.
Route::get('/areas-served/{area}/lead-pipe-replacement', function (string $area) {
    $model = \App\Models\AreaServed::where('slug', $area)->firstOrFail();
    $info = \App\Support\LeadLineInfo::forSlug($area);

    $seo = app(\App\Support\SEO\SEOBuilder::class);
    $seo->title("Lead Pipe Replacement in {$model->city}, IL — Who Pays & How It Works")
        ->description(\Illuminate\Support\Str::limit(
            ($info['found_official_info'] ?? false) && ! empty($info['cost_coverage']) && ! preg_match('/not published/i', (string) $info['cost_coverage'])
                ? "{$model->city} lead service line replacement: {$info['cost_coverage']} How to check your line, apply, and what remodelers should know."
                : "Lead water service line replacement in {$model->city}, IL — how to check your line, what Illinois law requires, and how replacement gets coordinated during a remodel.",
            158
        ))
        ->canonical(url("/areas-served/{$area}/lead-pipe-replacement"));

    if (! \App\Support\LeadLineInfo::hasOfficialInfo($area)) {
        $seo->markNoindex();
    }

    return view('lead-line-page', ['area' => $model, 'info' => $info]);
})->name('areas.lead-line');

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
Route::get('/services/mudroom-remodeling', ServicePage::class)
    ->defaults('service', 'mudroom-remodeling')
    ->name('services.mudroom');

// 301 redirects from old short service URLs
Route::redirect('/services/mudroom', '/services/mudroom-remodeling', 301);
Route::redirect('/services/mudrooms', '/services/mudroom-remodeling', 301);
Route::redirect('/services/laundry-room', '/services/mudroom-remodeling', 301);
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
// Non-branded homeowner guide: captures "how to choose / what to look for"
// research intent and feeds AI engines, without competitor-brand dependency.
Route::get('/how-to-choose-a-remodeling-contractor', fn () => view('how-to-choose'))
    ->name('guide.choose-contractor');

Route::get('/compare', CompareIndexPage::class)->name('compare.index');
Route::get('/compare/{slug}', CompareCompetitorPage::class)
    ->where('slug', '[a-z0-9\-]+')
    ->name('compare.show');

// Trust/money pages: financing guidance, written warranty, named process.
// Static views — title/meta set via layout props, FAQ schema on each.
Route::get('/financing', fn () => view('financing'))->name('financing');
Route::get('/warranty', fn () => view('warranty'))->name('warranty');
Route::get('/process', fn () => view('process'))->name('process');

// Cost-guide hub: year-stamped pricing pages from the same published ranges
// as geo-answers.php (see config/remodel-costs.php).
Route::get('/costs', fn () => view('costs-index'))->name('costs.index');
Route::get('/costs/{slug}', function (string $slug) {
    $guide = collect(config('remodel-costs.guides', []))->firstWhere('slug', $slug);
    abort_unless($guide && config('remodel-costs.enabled', true), 404);

    return view('cost-page', ['guide' => $guide]);
})->where('slug', '[a-z0-9\-]+')->name('costs.show');

// Insurance-claim repair cluster: damage-type rebuild guides
// (see config/insurance-claims.php — GC rebuild positioning, never public adjusting).
Route::get('/insurance-claims', fn () => view('insurance-claims-index'))->name('insurance-claims.index');
Route::get('/insurance-claims/{slug}', function (string $slug) {
    $claim = collect(config('insurance-claims.claims', []))->firstWhere('slug', $slug);
    abort_unless($claim && config('insurance-claims.enabled', true), 404);

    return view('insurance-claim-page', ['claim' => $claim]);
})->where('slug', '[a-z0-9\-]+')->name('insurance-claims.show');

// Trade-partner pages: how GS (as GC) works with its licensed/vetted trades.
Route::get('/trades', \App\Livewire\TradesIndexPage::class)->name('trades.index');
Route::get('/trades/{slug}', \App\Livewire\TradePage::class)
    ->where('slug', '[a-z0-9\-]+')
    ->name('trades.show');

// Demand-driven programmatic landing pages (Autopilot-generated, proof-gated).
Route::get('/remodeling/{slug}', \App\Livewire\LandingPageShow::class)
    ->where('slug', '[a-z0-9\-]+')
    ->name('landing.show');

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

    // First-party analytics (phone/email/form click tracking)
    Route::get('/analytics', \App\Livewire\Admin\SiteAnalytics::class)->name('analytics.index');

    // Client-side JavaScript errors captured from real visitors
    Route::get('/js-errors', \App\Livewire\Admin\ClientErrors::class)->name('js-errors.index');

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
    Route::get('/autopilot', \App\Livewire\Admin\SeoAutopilotPanel::class)->name('autopilot.index');
    Route::get('/landing-pages', \App\Livewire\Admin\LandingPages::class)->name('landing-pages.index');
    Route::get('/gsc-errors', \App\Livewire\Admin\GscErrors::class)->name('gsc-errors.index');
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

    Route::get('/platforms/meta/callback', function (\Illuminate\Http\Request $request) {
        $code = $request->query('code');
        if (! $code) {
            $err = $request->query('error_description') ?? $request->query('error') ?? 'No authorisation code returned.';
            session()->flash('platforms-error', 'Meta connection cancelled: ' . $err);
            return redirect()->route('admin.platforms.index');
        }

        $result = app(\App\Services\MetaSocialService::class)
            ->exchangeCodeAndStore($code, route('admin.platforms.meta-callback'));

        if ($result['success']) {
            $msg = 'Meta connected: ' . ($result['page_name'] ?? 'Facebook Page');
            if (! empty($result['ig_username'])) {
                $msg .= ' · @' . $result['ig_username'];
            }
            session()->flash('platforms-success', $msg);
        } else {
            session()->flash('platforms-error', 'Meta connection failed: ' . ($result['error'] ?? 'unknown'));
        }

        return redirect()->route('admin.platforms.index');
    })->name('platforms.meta-callback');
});
