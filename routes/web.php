<?php

use App\Http\Controllers\AdminProxyController;
use App\Http\Controllers\AiFeedController;
use App\Http\Controllers\ClientErrorController;
use App\Http\Controllers\GeoAnswersController;
use App\Http\Controllers\TrackEventController;
use App\Http\Controllers\YelpCookieIngestController;
use App\Http\Middleware\CacheStaticAssets;
use App\Http\Middleware\CaptureUtmParameters;
use App\Http\Middleware\DetectCountry;
use App\Http\Middleware\NoIndexNonProduction;
use App\Http\Middleware\RedirectLegacyUrls;
use App\Http\Middleware\ResolveAdminSite;
use App\Http\Middleware\ResolveSite;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TenantRouteGuard;
use App\Http\Middleware\TrackDomainSource;
use App\Livewire\Admin\AreaForm;
use App\Livewire\Admin\AreaList;
use App\Livewire\Admin\ClientErrors;
use App\Livewire\Admin\ContactSubmissions;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\GscErrors;
use App\Livewire\Admin\LandingPages;
use App\Livewire\Admin\Login;
use App\Livewire\Admin\PlatformsSettings;
use App\Livewire\Admin\ProjectForm;
use App\Livewire\Admin\ProjectList;
use App\Livewire\Admin\SeoReports;
use App\Livewire\Admin\SiteAnalytics;
use App\Livewire\Admin\SocialMediaPosts;
use App\Livewire\Admin\TagList;
use App\Livewire\Admin\TestimonialForm;
use App\Livewire\Admin\TestimonialList;
use App\Livewire\AreaPage;
use App\Livewire\AreasServedPage;
use App\Livewire\CompareCompetitorPage;
use App\Livewire\CompareIndexPage;
use App\Livewire\DesignPartnersPage;
use App\Livewire\JobsPage;
use App\Livewire\LandingPageShow;
use App\Livewire\ProjectImagePage;
use App\Livewire\ProjectPage;
use App\Livewire\ServiceAreaIndex;
use App\Livewire\ServicePage;
use App\Livewire\ServicesPage;
use App\Livewire\TestimonialPage;
use App\Livewire\TimelapsesPage;
use App\Livewire\TradePage;
use App\Livewire\TradesIndexPage;
use App\Livewire\ZipCodePage;
use App\Models\AreaServed;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\ShortLink;
use App\Models\Site;
use App\Services\GoogleBusinessProfileService;
use App\Services\GoogleSearchConsoleService;
use App\Services\MetaSocialService;
use App\Services\SeoService;
use App\Support\DevSites;
use App\Support\LeadLineInfo;
use App\Support\PermitGuideInfo;
use App\Support\SEO\SEOBuilder;
use App\Support\Theme;
use Hszope\LaravelAigeo\Http\Middleware\InjectGeoHeaders;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;

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

// Yelp session hand-off from the browser extension. The owner logs in to
// biz.yelp.com in their own browser (a real residential IP, so DataDome never
// fires) and the extension posts the resulting cookies here; a queued job
// injects them into the automation profile. Bearer-token auth, CSRF-exempt in
// bootstrap/app.php. Throttled because it is internet-reachable.
Route::post('/api/yelp/cookies', YelpCookieIngestController::class)
    ->middleware('throttle:20,1')
    ->name('yelp.cookies.ingest');

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
        ? 'https://search.google.com/local/writereview?placeid='.urlencode($placeId)
        : 'https://www.google.com/maps/search/?api=1&query='.urlencode('GS Construction Remodeling');

    return redirect()->away($target, 302);
})->name('review.write');

// 301 redirects from legacy /testimonials URLs (preserves link equity).
Route::get('/testimonials', function () {
    // gs.construction canonicalised testimonials into /reviews years of links
    // ago — keep that 301. jpeterson has a first-class testimonials page.
    if (Site::current()->slug === 'jpeterson') {
        return view('testimonials-page');
    }

    return redirect('/reviews', 301);
})->name('testimonials.index');
Route::get('/testimonials/{testimonial}', function (string $testimonial) {
    return redirect("/reviews/{$testimonial}", 301);
})->name('testimonials.show');

Route::get('/about', function () {
    // SeoService writes GS-specific meta; other tenants set titles in-view.
    if (Site::current()->slug === 'gsc') {
        SeoService::about();
    }

    return view('about');   // resolved through the theme overlay per tenant
})->name('about');

Route::get('/contact', function () {
    if (Site::current()->slug === 'gsc') {
        SeoService::contact();
    }

    return view('contact');
})->name('contact');

// J. Peterson Design portfolio (tenant-gated: only jpeterson claims /portfolio).
Route::get('/portfolio', fn () => view('portfolio'))->name('portfolio');

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

    if (! isset($typeMap[$type])) {
        abort(404);
    }

    // Pass the type to the view directly — NOT request()->merge(). merge()
    // writes into the query bag, and the canonical builder echoes unknown
    // query params, so /projects/kitchens declared
    // rel=canonical href="/projects/kitchens?type=kitchen" — a URL that is in
    // nobody's sitemap. Google overrode the canonical on these pages
    // ("Duplicate, Google chose different canonical than user").
    SeoService::projects(null, $typeMap[$type]);

    return view('projects', ['projectTypeFilter' => $typeMap[$type]]);
})->where('type', 'kitchens|bathrooms|home-remodeling')
    ->name('projects.type');

// API endpoint for background image preloading
Route::get('/api/project-images', function () {
    $images = ProjectImage::all()
        ->flatMap(function ($image) {
            $urls = [];
            // Get medium size (most commonly used)
            $url = $image->getWebpThumbnailUrl('medium') ?? $image->getThumbnailUrl('medium');
            if ($url) {
                $urls[] = $url;
            }
            // Get thumb for blur placeholders
            $thumb = $image->getWebpThumbnailUrl('thumb') ?? $image->getThumbnailUrl('thumb');
            if ($thumb) {
                $urls[] = $thumb;
            }

            return $urls;
        })
        ->unique()
        ->values();

    return response()->json($images)
        ->header('Cache-Control', 'public, max-age=3600'); // Cache for 1 hour
})->name('api.project-images');

// Every before/after timelapse on one page. Declared BEFORE /projects/{project}
// so "timelapses" is not swallowed as a project slug.
Route::get('/timelapses', TimelapsesPage::class)->name('timelapses.index');

Route::get('/projects/{project}', ProjectPage::class)->name('projects.show');
// Scope {image} to its parent {project} so photo slugs that are duplicated
// across projects resolve to the image under THIS project (an unscoped bind
// picks the first same-slug image globally and 404s on the project mismatch).
Route::get('/projects/{project}/photos/{image:slug}', ProjectImagePage::class)
    ->scopeBindings()
    ->name('projects.image');

Route::get('/services', function () {
    $site = Site::current();

    if ($site->slug !== 'gsc') {
        // A THEMED view only. resources/views/services.blade.php is GS
        // Construction's own hardcoded six-card page; falling back to it would
        // serve GSC's services to any tenant that claims /services without
        // supplying its own view.
        //
        // Test the theme FILE, not a "themes/{theme}/services" view name.
        // Theme::apply() prepends resources/themes/{theme} to the view finder,
        // so themed views resolve under their plain name — the path-style name
        // resolved to nothing and every non-gsc tenant 404'd here, jpeterson
        // included, despite having services.blade.php in its theme.
        abort_unless(is_file(Theme::path($site).'/services.blade.php'), 404);

        // Theme-first via the finder; the abort above is what keeps a
        // theme-less tenant from falling through to GSC's shared page.
        return view('services');
    }

    // Livewire full-page components are invokable controllers.
    return app()->call(ServicesPage::class.'@__invoke');
})->name('services.index');

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
    $model = AreaServed::where('slug', $area)->firstOrFail();
    $info = LeadLineInfo::forSlug($area);

    $seo = app(SEOBuilder::class);
    $seo->title("Lead Pipe Replacement in {$model->city}, IL — Who Pays & How It Works")
        ->description(Str::limit(
            ($info['found_official_info'] ?? false) && ! empty($info['cost_coverage']) && ! preg_match('/not published/i', (string) $info['cost_coverage'])
                ? "{$model->city} lead service line replacement: {$info['cost_coverage']} How to check your line, apply, and what remodelers should know."
                : "Lead water service line replacement in {$model->city}, IL — how to check your line, what Illinois law requires, and how replacement gets coordinated during a remodel.",
            158
        ))
        ->canonical(url("/areas-served/{$area}/lead-pipe-replacement"));

    if (! LeadLineInfo::hasOfficialInfo($area)) {
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
// basement-remodeling and home-additions were missing from this group even
// though the same old URL shape existed for them too. The gap was measurable:
// 260 distinct /areas-served/{area}/{service} 404 paths in tracked_404s
// (2,179 hits, Googlebot among them) for exactly these two services.
Route::get('/areas-served/{area}/basement-remodeling', function (string $area) {
    return redirect("/areas-served/{$area}/services/basement-remodeling", 301);
});
Route::get('/areas-served/{area}/home-additions', function (string $area) {
    return redirect("/areas-served/{$area}/services/home-additions", 301);
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
// WebSub-enabled Atom feed of recently updated pages — the legitimate "push"
// channel to Google: on content changes we ping the hub, subscribed crawlers
// fetch this feed within minutes and discover the changed URLs.
Route::get('/feed/updates.atom', function () {
    $entries = collect()
        ->concat(AreaServed::orderByDesc('updated_at')->limit(30)->get()
            ->map(fn ($a) => ['url' => url('/areas-served/'.$a->slug), 'title' => $a->city.' Remodeling — GS Construction', 'updated' => $a->updated_at]))
        ->concat(Project::where('is_published', true)->orderByDesc('updated_at')->limit(30)->get()
            ->map(fn ($p) => ['url' => url('/projects/'.$p->slug), 'title' => $p->title, 'updated' => $p->updated_at]))
        ->filter(fn ($e) => $e['updated'] !== null)
        ->sortByDesc('updated')
        ->take(40)
        ->values();

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<feed xmlns="http://www.w3.org/2005/Atom">'."\n"
        .'  <title>GS Construction — Recently Updated Pages</title>'."\n"
        .'  <id>'.url('/feed/updates.atom').'</id>'."\n"
        .'  <link rel="self" href="'.url('/feed/updates.atom').'"/>'."\n"
        .'  <link rel="hub" href="https://pubsubhubbub.appspot.com/"/>'."\n"
        .'  <link rel="alternate" href="'.url('/').'"/>'."\n"
        .'  <updated>'.($entries->first()['updated'] ?? now())->toAtomString().'</updated>'."\n";
    foreach ($entries as $e) {
        $xml .= '  <entry>'."\n"
            .'    <id>'.e($e['url']).'</id>'."\n"
            .'    <title>'.e($e['title']).'</title>'."\n"
            .'    <link rel="alternate" href="'.e($e['url']).'"/>'."\n"
            .'    <updated>'.$e['updated']->toAtomString().'</updated>'."\n"
            .'  </entry>'."\n";
    }
    $xml .= '</feed>'."\n";

    return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=UTF-8'])
        ->setMaxAge(300)->setPublic();
})->name('feed.updates');

Route::get('/costs', fn () => view('costs-index'))->name('costs.index');
Route::get('/costs/{slug}', function (string $slug) {
    $guide = collect(config('remodel-costs.guides', []))->firstWhere('slug', $slug);
    abort_unless($guide && config('remodel-costs.enabled', true), 404);

    return view('cost-page', ['guide' => $guide]);
})->where('slug', '[a-z0-9\-]+')->name('costs.show');

// Building-permit guide cluster: per-town permit guides from researched
// official-source data (see app/Support/PermitGuideInfo.php).
Route::get('/permits', fn () => view('permits-index'))->name('permits.index');
Route::get('/permits/{slug}', function (string $slug) {
    $guide = PermitGuideInfo::forSlug($slug);
    abort_unless((bool) $guide, 404);

    return view('permit-guide-page', ['slug' => $slug, 'guide' => $guide]);
})->where('slug', '[a-z0-9\-]+')->name('permits.show');

// Insurance-claim repair cluster: damage-type rebuild guides
// (see config/insurance-claims.php — GC rebuild positioning, never public adjusting).
Route::get('/insurance-claims', fn () => view('insurance-claims-index'))->name('insurance-claims.index');
Route::get('/insurance-claims/{slug}', function (string $slug) {
    $claim = collect(config('insurance-claims.claims', []))->firstWhere('slug', $slug);
    abort_unless($claim && config('insurance-claims.enabled', true), 404);

    return view('insurance-claim-page', ['claim' => $claim]);
})->where('slug', '[a-z0-9\-]+')->name('insurance-claims.show');

// Design studios whose work we build. Sibling to /trades: that page is who
// does the work, this one is who designed it.
Route::get('/design-partners', DesignPartnersPage::class)->name('design-partners.index');

// Trade-partner pages: how GS (as GC) works with its licensed/vetted trades.
Route::get('/trades', TradesIndexPage::class)->name('trades.index');
Route::get('/trades/{slug}', TradePage::class)
    ->where('slug', '[a-z0-9\-]+')
    ->name('trades.show');

// Demand-driven programmatic landing pages (Autopilot-generated, proof-gated).
Route::get('/remodeling/{slug}', LandingPageShow::class)
    ->where('slug', '[a-z0-9\-]+')
    ->name('landing.show');

/*
| OAuth callbacks — deliberately at the ORIGINAL /admin/{site}/… paths, not
| /admin-legacy. These exact URLs are registered as authorized redirect URIs
| in the Google Cloud and Meta developer consoles; moving them with the rest
| of the legacy admin would have broken every OAuth flow with a
| redirect_uri_mismatch. Registered BEFORE the /admin/{path?} proxy
| catch-all below, so they match first and never get proxied. route()
| generation (used for the redirect_uri parameter) keeps producing these
| same /admin/… URLs because the names are unchanged.
*/
Route::middleware(['auth', 'noindex', ResolveAdminSite::class])
    ->prefix('admin/{site}')
    ->where(['site' => '[a-z0-9\-]+\.[a-z0-9.\-]+'])
    ->name('admin.')
    ->group(function () {
        Route::get('/platforms/gbp/callback', function (Request $request) {
            $code = $request->query('code');
            if (! $code) {
                // Post-callback landing is the CENTRAL admin now, not this app's
                // own session — session()->flash() can't cross apps, so the
                // outcome travels as a query param instead. The central
                // Platforms screen (ss-systems) reads it once and shows the
                // banner. See routes/api-admin/platforms.php for the read side.
                return redirect('/admin/gsc/platforms?error='.urlencode('Authorization cancelled or failed — no code returned.'));
            }

            $service = app(GoogleBusinessProfileService::class);
            $result = $service->exchangeCodeAndStore($code, route('admin.platforms.gbp-callback'));

            if ($result['success']) {
                return redirect('/admin/gsc/platforms?connected=gbp');
            }

            return redirect('/admin/gsc/platforms?error='.urlencode('OAuth failed: '.($result['error'] ?? 'Unknown error')));
        })->name('platforms.gbp-callback');

        Route::get('/platforms/gsc/callback', function (Request $request) {
            $code = $request->query('code');
            if (! $code) {
                // See the gbp callback above: outcome travels as a query param,
                // not a session flash, because the landing page is the central
                // admin (a different app / session) now.
                return redirect('/admin/gsc/platforms?error='.urlencode('Authorization cancelled or failed — no code returned.'));
            }

            $result = app(GoogleSearchConsoleService::class)
                ->exchangeCodeAndStore($code, route('admin.platforms.gsc-callback'));

            if ($result['success']) {
                return redirect('/admin/gsc/platforms?connected=gsc');
            }

            return redirect('/admin/gsc/platforms?error='.urlencode('OAuth failed: '.($result['error'] ?? 'Unknown error')));
        })->name('platforms.gsc-callback');

        Route::get('/platforms/meta/callback', function (Request $request) {
            $code = $request->query('code');
            if (! $code) {
                $err = $request->query('error_description') ?? $request->query('error') ?? 'No authorisation code returned.';

                // See the gbp callback above: outcome travels as a query param,
                // not a session flash, because the landing page is the central
                // admin (a different app / session) now.
                return redirect('/admin/gsc/platforms?error='.urlencode('Meta connection cancelled: '.$err));
            }

            $result = app(MetaSocialService::class)
                ->exchangeCodeAndStore($code, route('admin.platforms.meta-callback'));

            if ($result['success']) {
                return redirect('/admin/gsc/platforms?connected=meta');
            }

            return redirect('/admin/gsc/platforms?error='.urlencode('Meta connection failed: '.($result['error'] ?? 'unknown')));
        })->name('platforms.meta-callback');

        // Self-pairing for the Yelp Session Bridge extension. Its content
        // script calls this same-origin (with the admin session cookie) and
        // configures itself with the returned token — nobody copies tokens
        // by hand. Kept at the ORIGINAL /admin/{site}/… path for the same
        // reason as the OAuth callbacks above: the URL is baked into the
        // shipped extension.
        Route::get('/platforms/extension-pairing', [\App\Http\Controllers\YelpCookieIngestController::class, 'pairing'])
            ->name('platforms.extension-pairing');
    });

// Signed, unauthenticated Yelp/Instagram remote-login viewer redirect for the
// central admin's Platforms screen — kept in its own file (not inline here)
// so this one central-admin-facing, non-'auth' route is obvious at a glance;
// see routes/platforms-viewer.php for the full rationale. MUST be registered
// here, before the /admin/{path?} proxy catch-all below — same reason the
// OAuth callbacks and extension-pairing route above are: a route registered
// after that catch-all never gets a chance to match, since Route::any(
// '/admin/{path?}') greedily matches every /admin/... GET first.
require __DIR__.'/platforms-viewer.php';

/*
| /admin belongs to the CENTRAL admin now: a transparent proxy relaying
| every request byte-for-byte to ss-systems (see AdminProxyController — the
| session cookie and CSRF token in play are ss-systems', which is why the
| route strips this app's whole web-group session/cookie/CSRF stack plus
| the tenant/tracking middleware appended in bootstrap/app.php; if the web
| group gains new middleware later, review whether it belongs in this
| exclusion list). This app's own admin — including the ops screens the
| central admin doesn't cover yet (SEO, social, platforms, analytics,
| JS errors) — lives on at /admin-legacy below, unchanged except for the
| prefix; route names stay admin.* so nothing else moved.
*/
Route::any('/admin/{path?}', [AdminProxyController::class, 'handle'])
    ->where('path', '.*')
    // 2000/min: every admin page load now rides this route several times
    // (HTML + Livewire endpoints + proxied assets) — 120/min throttled
    // ordinary clicking-around.
    ->middleware('throttle:2000,1')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
        ResolveSite::class,
        TenantRouteGuard::class,
        DetectCountry::class,
        TrackDomainSource::class,
        RedirectLegacyUrls::class,
        CacheStaticAssets::class,
        CaptureUtmParameters::class,
        SecurityHeaders::class,
        NoIndexNonProduction::class,
        InjectGeoHeaders::class,
    ]);

// Legacy admin auth
Route::get('/admin-legacy/login', Login::class)->name('admin.login')->middleware(['guest', 'noindex']);
Route::post('/admin-legacy/logout', function () {
    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('admin.login');
})->name('admin.logout')->middleware('noindex');

// Legacy admin routes (protected by auth)
// Admin is site-scoped by URL: /admin-legacy/{site}/…  e.g. /admin-legacy/gs.construction/projects
//
// {site} is constrained to hostname-shaped segments (must contain a dot), which
// is what lets the legacy /admin-legacy/projects paths below fall through to a
// redirect instead of being mis-parsed as a site key named "projects".
//
// ResolveAdminSite sets URL::defaults(['site' => …]), so every existing
// route('admin.*') call keeps working untouched.
Route::middleware(['auth', 'noindex', ResolveAdminSite::class])
    ->prefix('admin-legacy/{site}')
    ->where(['site' => '[a-z0-9\-]+\.[a-z0-9.\-]+'])
    ->name('admin.')
    ->group(function () {
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
        Route::get('/analytics', SiteAnalytics::class)->name('analytics.index');

        // Client-side JavaScript errors captured from real visitors
        Route::get('/js-errors', ClientErrors::class)->name('js-errors.index');

        // Testimonials / Reviews
        Route::get('/testimonials', TestimonialList::class)->name('testimonials.index');
        Route::get('/testimonials/create', TestimonialForm::class)->name('testimonials.create');
        Route::get('/testimonials/{testimonial}/edit', TestimonialForm::class)->name('testimonials.edit');

        // Service Areas
        Route::get('/areas', AreaList::class)->name('areas.index');
        Route::get('/areas/create', AreaForm::class)->name('areas.create');
        Route::get('/areas/{area}/edit', AreaForm::class)->name('areas.edit');

        // Social Media
        Route::get('/social-media', SocialMediaPosts::class)->name('social-media.index');

        // Platforms (Google Business Profile, Yelp, etc.)
        Route::get('/platforms', PlatformsSettings::class)->name('platforms.index');

        // (Yelp extension self-pairing moved to the pinned /admin/{site}
        // group next to the OAuth callbacks — the extension's content script
        // has the /admin/… URL baked in, so it must not move with the
        // legacy rename.)

        // SEO weekly reports dashboard — the autopilot panel now lives INSIDE it
        // (nested Livewire island), so recommendations and the machine acting on
        // them are one page. The old standalone URL 301s to the merged page; the
        // route keeps its name so existing route('admin.autopilot.index') links
        // (dashboard tiles, landing-pages button) keep working and now land on
        // the panel's anchor.
        Route::get('/seo-reports/{report?}', SeoReports::class)->name('seo-reports.index');
        Route::get('/autopilot', function (string $site) {
            return redirect()->route('admin.seo-reports.index', ['site' => $site], 301)
                ->withFragment('autopilot');
        })->name('autopilot.index');
        Route::get('/landing-pages', LandingPages::class)->name('landing-pages.index');
        Route::get('/gsc-errors', GscErrors::class)->name('gsc-errors.index');
    });

/*
|--------------------------------------------------------------------------
| Legacy admin hub entry points
|--------------------------------------------------------------------------
| /admin-legacy          -> site picker (the hub landing page)
| /admin-legacy/anything -> legacy path from before admin was site-scoped;
|                           sent to the same page under the current site so
|                           old bookmarks keep working. (/admin bookmarks now
|                           land on the central admin's login instead.)
|
| Both live OUTSIDE the {site} group. The group's constraint requires a dot in
| the segment, so "projects" can never be mistaken for a site key.
*/
Route::get('/admin-legacy', function (Request $request) {
    // Only what this user may administer. A client login is scoped to one
    // tenant, so it must never be shown a menu of everybody else's sites —
    // the picker was leaking the full client list to anyone who logged in.
    $sites = $request->user()->accessibleSites();

    abort_if($sites->isEmpty(), 403, 'Your account is not linked to a site.');

    // Nothing to choose from: go straight in. This is the normal path for a
    // client login, which should never see a picker at all.
    if ($sites->count() === 1) {
        return redirect("/admin-legacy/{$sites->first()->primary_host}");
    }

    // The host already answered the question. Arriving at gs.construction/admin
    // and being asked "which site?" is a step with one sensible answer, so
    // honour the tenant this request came in on. Reached over a host that
    // names no tenant (127.0.0.1, a bare IP) this is the default site, which
    // is the right guess for the same reason.
    $current = Site::current();
    if ($site = $sites->firstWhere('id', $current->id)) {
        return redirect("/admin-legacy/{$site->primary_host}");
    }

    // The current tenant is not one this user administers — the picker is a
    // real question now.
    return view('admin.site-picker', ['sites' => $sites]);
})->middleware(['auth', 'noindex'])->name('admin.hub');

// The picker, kept reachable on purpose. /admin now goes straight to the
// tenant you arrived on, so without this an operator with several sites would
// have no route to the others — there is no switcher in the admin chrome.
Route::get('/admin-legacy/sites', function (Request $request) {
    $sites = $request->user()->accessibleSites();

    abort_if($sites->isEmpty(), 403, 'Your account is not linked to a site.');

    return view('admin.site-picker', ['sites' => $sites]);
})->middleware(['auth', 'noindex'])->name('admin.sites');

Route::get('/admin-legacy/{path}', function (string $path) {
    return redirect('/admin-legacy/'.Site::current()->primary_host.'/'.ltrim($path, '/'), 301);
})->where('path', '.*')->middleware(['auth', 'noindex']);

/*
|--------------------------------------------------------------------------
| J. Peterson Design — market pages
|--------------------------------------------------------------------------
| /chicago, /atlanta, /south-haven. Slugs come from
| config/sites/jpeterson/markets.php (the single source for the studio's
| metros), so a market added there registers its route in the same edit.
| The same file feeds jpeterson's exclusive_paths claim in config/sites.php,
| so these paths 404 on every other tenant.
*/
$jpMarketSlugs = array_column(
    (array) data_get(require config_path('sites/jpeterson/markets.php'), 'list', []),
    'slug',
);

if ($jpMarketSlugs !== []) {
    Route::get('/{market}', function (string $market) {
        // markets.list is only overlaid for the jpeterson tenant; on any other
        // site TenantRouteGuard has already 404'd before we get here, and this
        // abort covers the belt-and-braces case anyway.
        $data = collect(config('markets.list', []))->firstWhere('slug', $market);
        abort_unless($data, 404);

        return view('market', ['market' => $data]);
    })->where('market', implode('|', array_map('preg_quote', $jpMarketSlugs)))->name('market.show');
}

/*
|--------------------------------------------------------------------------
| Local tenant register (/_sites)
|--------------------------------------------------------------------------
| Registered ONLY in local, so a production request for /_sites is a plain
| router 404 rather than an authorisation decision that could be got wrong.
| Forge builds its route cache with APP_ENV=production, so these are absent
| from the cached table too.
|
| The public counterpart to the /admin site picker: every tenant, its local
| host, what it overrides, and what a given path would do on each.
*/
if (app()->environment('local')) {
    Route::get('/_sites', function (Request $request) {
        $path = '/'.ltrim((string) $request->query('path', '/'), '/');

        return view('dev.sites-index', [
            'sites' => DevSites::register($path),
            'current' => Site::current(),
            'via' => DevSites::resolvedVia(),
            'path' => $path,
            'port' => DevSites::port(),
        ]);
    })->name('dev.sites');

    // Toggle the injected dev bar. A cookie rather than a query param so it
    // survives navigation — the bar is genuinely intrusive in the screenshots
    // this repo verifies theme work with.
    Route::get('/_sites/bar', function (Request $request) {
        $off = $request->query('state') === 'off';
        $back = (string) $request->query('back', '/');

        return redirect()->away($back)->withCookie(
            cookie('dev_bar', $off ? 'off' : 'on', 60 * 24 * 30)
        );
    })->name('dev.sites.bar');
}
