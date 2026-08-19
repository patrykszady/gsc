<?php

use App\Http\Middleware\AuthenticateAdminApi;
use App\Http\Middleware\CacheStaticAssets;
use App\Http\Middleware\CaptureUtmParameters;
use App\Http\Middleware\DetectCountry;
use App\Http\Middleware\DevSiteBar;
use App\Http\Middleware\NoIndexHeader;
use App\Http\Middleware\NoIndexNonProduction;
use App\Http\Middleware\PinAdminApiTenant;
use App\Http\Middleware\RedirectLegacyUrls;
use App\Http\Middleware\ResolveSite;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TenantRouteGuard;
use App\Http\Middleware\Track404Responses;
use App\Http\Middleware\TrackAiTraffic;
use App\Http\Middleware\TrackDomainSource;
use App\Models\Site;
use App\Support\RenamedProjectRedirector;
use App\Support\SiteConfig;
use App\Support\Theme;
use Hszope\LaravelAigeo\Http\Middleware\InjectGeoHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/admin-legacy/login');

        // Respect X-Forwarded-* headers from Cloudflare Tunnel/reverse proxies.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        // The Yelp cookie ingest endpoint is called by a browser extension
        // service worker, which has no Laravel session and therefore no CSRF
        // token. It authenticates with a bearer token instead (constant-time
        // compare in YelpCookieIngestController).
        $middleware->validateCsrfTokens(except: [
            'api/yelp/cookies',
            // The central-admin proxy: POSTs under /admin/* carry
            // ss-systems' CSRF token, not this app's (the route also strips
            // this app's session/CSRF middleware — routes/web.php).
            'admin',
            'admin/*',
        ]);

        // Bot blocking now handled by Cloudflare WAF + Bot Fight Mode

        // SEO: Track domain source for analytics, handle legacy redirects, cache static assets, and add security headers
        // DetectCountry: Uses Cloudflare CF-IPCountry header for geo-based features (GA only for US, visible Turnstile for non-US)
        $middleware->web(append: [
            // Multi-tenant: resolve the Site from the request host. Appended,
            // so it sits after StartSession (which the ?site= session pin
            // needs) — see the priority tweak below for the one middleware it
            // must nonetheless outrank.
            ResolveSite::class,
            // Must follow ResolveSite: it needs the tenant to decide ownership.
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

        // Route-model binding must not run before the tenant is known.
        // SubstituteBindings sits at priority 10 and the appended ResolveSite
        // would otherwise run after it, so /projects/{project},
        // /reviews/{testimonial} and /areas-served/{area} would resolve their
        // model through BelongsToSite's global scope while Site::current() is
        // still the lazily-memoised default site — i.e. a tenant would 404 on
        // its own records, or worse, bind another tenant's. Inserting
        // ResolveSite ahead of SubstituteBindings in the priority list keeps
        // it after StartSession (priority 4), so the session pin still works.
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveSite::class,
        );

        // Route-level alias: send X-Robots-Tag: noindex, nofollow on admin pages
        // so search engines drop them from the index even when discovered via
        // a backlink (robots.txt Disallow only blocks crawling, not indexing).
        $middleware->alias([
            'noindex' => NoIndexHeader::class,
            // Management API (/api/admin/v1): bearer auth + gsc tenant pin.
            'admin.api.auth' => AuthenticateAdminApi::class,
            'admin.api.tenant' => PinAdminApiTenant::class,
        ]);

        // Global terminating middleware: track 404 responses (must be global,
        // since unmatched routes throw NotFoundHttpException before reaching
        // any route group middleware).
        $middleware->append(Track404Responses::class);

        // Counts AI-assistant referrals and AI-crawler fetches (terminate()
        // only) so GEO work is measurable on the SEO Reports page.
        $middleware->append(TrackAiTraffic::class);

        // Local-only tenant badge/switcher, injected into every HTML response,
        // plus X-Dev-Site headers on responses with no body to inject into
        // (redirects, JSON, the Livewire update endpoint).
        //
        // GLOBAL, for the same reason Track404Responses is: TenantRouteGuard
        // aborts with a 404 before any middleware appended to the web group
        // runs, and a tenant-guard 404 is precisely when you need to be told
        // which tenant you are on. Reading the dev_bar cookie still works from
        // out here because this only inspects the request on the RESPONSE
        // phase, by which point EncryptCookies has decrypted it in place.
        //
        // Gated inside the middleware rather than here: this closure runs on
        // afterResolving(Kernel), before the environment is detected, so
        // app()->environment() would throw "Target class [env] does not
        // exist". DevSiteBar::handle() returns the response untouched outside
        // local. The /_sites routes ARE gated at registration — routes load
        // after bootstrapping, where the environment is known.
        $middleware->append(DevSiteBar::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Management API errors must be JSON even when the caller forgets
        // an Accept header (ss-systems always sends one; curl users don't).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 404 tracking is handled by App\Http\Middleware\Track404Responses
        // (terminating middleware on web group). Laravel filters
        // NotFoundHttpException out of report callbacks, so reporter-based
        // tracking does not fire.

        // Resolve the tenant before ANY error view renders.
        //
        // ResolveSite lives in the `web` group, which only runs once a route
        // has matched. An unknown URL throws NotFoundHttpException during
        // routing, so error pages rendered without it fell back to the default
        // site: every tenant served GS Construction's 404 — its brand name in
        // the <title> and its phone number in the body.
        //
        // Returning null hands rendering back to Laravel; this callback exists
        // only for the side effect of binding the tenant, theme and config
        // overlay first. Guarded on `request` so console/queue are untouched.
        $exceptions->render(function (Throwable $e, Request $request) {
            // Guard on the OVERLAY, not on site.current. Global middleware
            // (Track404Responses, DevSiteBar) can call Site::current() before
            // routing, which binds the tenant while leaving the theme and the
            // config overlay unapplied — so a "site is bound" check passed
            // while the page still rendered the default tenant's brand.
            if (! app()->bound('site.overlay_applied')) {
                $site = Site::forDevHost($request->getHost())
                    ?? Site::forHost($request->getHost())
                    ?? Site::forPreviewHost($request->getHost())
                    ?? Site::default();

                Site::setCurrent($site);
                Theme::apply($site);
                SiteConfig::applyRuntime($site);
                app()->instance('site.overlay_applied', true);
            }

            // A renamed project 301s to its new URL, together with everything
            // nested under it (photo pages live at /projects/{p}/photos/{i}).
            //
            // Handled here rather than in middleware: route-model binding
            // THROWS NotFoundHttpException from inside SubstituteBindings, so
            // a middleware appended to the web group never sees it — the
            // exception is raised before that middleware's frame is reached.
            // The render hook is the one place guaranteed to observe it.
            if ($e instanceof NotFoundHttpException
                && $request->isMethod('GET')) {
                if ($redirect = RenamedProjectRedirector::for($request)) {
                    return $redirect;
                }
            }

            return null;
        });
    })->create();
