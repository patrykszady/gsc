<?php

namespace App\Providers;

use App\Http\Middleware\ResolveAdminSite;
use App\Http\Middleware\ResolveSite;
use App\Models\AreaServed;
use App\Models\BlogPost;
use App\Models\LandingPage;
use App\Models\PlatformSetting;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\Service;
use App\Models\Site;
use App\Models\Testimonial;
use App\Observers\AreaServedObserver;
use App\Observers\BlogPostObserver;
use App\Observers\ProjectImageObserver;
use App\Observers\ProjectObserver;
use App\Observers\TestimonialObserver;
use App\Services\BingWebmasterService;
use App\Services\GoogleSearchConsoleService;
use App\Support\Areas\RetiredAreaRedirect;
use App\Support\GoogleBusinessListing;
use App\Support\GoogleOAuthApp;
use App\Support\PublicFeeds;
use App\Support\Seo\BingWriter;
use App\Support\Seo\Inspection\EloquentCoverageStore;
use App\Support\Seo\Inspection\FileSitemapSource;
use App\Support\Seo\Inspection\SearchConsoleUrlInspector;
use App\Support\Seo\Inspection\TrackedPathsFromModel;
use App\Support\SEO\RecrawlNudger;
use App\Support\Seo\Reports\ConfigSiteIdentity;
use App\Support\Seo\Reports\EloquentAreaCatalog;
use App\Support\Seo\Reports\EloquentClarityMetricsReader;
use App\Support\Seo\Reports\EloquentHealthDataReader;
use App\Support\Seo\Reports\EloquentPsiSnapshotReader;
use App\Support\Seo\Reports\EloquentQueryMetricsReader;
use App\Support\Seo\Reports\HttpPageFetcher;
use App\Support\Seo\Reports\HttpSiteCatalog;
use App\Support\Seo\Reports\LaravelSimpleCache;
use App\Support\Seo\SearchConsoleWriter as SiteSearchConsoleWriter;
use App\Support\SEO\SEOBuilder;
use App\Support\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Blaze\Blaze;
use Livewire\Livewire;
use Opcodes\LogViewer\Facades\LogViewer;
use Psr\SimpleCache\CacheInterface;
use SsSystems\Platform\Pulse\BeaconController;
use SsSystems\Platform\Pulse\Recorder;
use SsSystems\Platform\Pulse\SnapshotBuilder;
use SsSystems\Platform\Pulse\Storage\DatabaseTableStorage;
use SsSystems\Platform\Reports\Contracts\AreaCatalog;
use SsSystems\Platform\Reports\Contracts\ClarityMetricsReader;
use SsSystems\Platform\Reports\Contracts\HealthDataReader;
use SsSystems\Platform\Reports\Contracts\PageFetcher;
use SsSystems\Platform\Reports\Contracts\PsiSnapshotReader;
use SsSystems\Platform\Reports\Contracts\QueryMetricsReader;
use SsSystems\Platform\Reports\Contracts\SiteCatalog;
use SsSystems\Platform\Reports\Contracts\SiteIdentity;
use SsSystems\Platform\Seo\Bing\BingWebmasterClient;
use SsSystems\Platform\Seo\Bing\BingWriter as KitBingWriter;
use SsSystems\Platform\Seo\Inspection\Contracts\CoverageStore as CoverageStoreContract;
use SsSystems\Platform\Seo\Inspection\Contracts\SitemapSource as SitemapSourceContract;
use SsSystems\Platform\Seo\Inspection\Contracts\TrackedPaths as TrackedPathsContract;
use SsSystems\Platform\Seo\Inspection\Contracts\UrlInspector as UrlInspectorContract;
use SsSystems\Platform\Seo\Inspection\UrlInspectionQuota as KitUrlInspectionQuota;
use SsSystems\Platform\Seo\SearchConsoleClient;
use SsSystems\Platform\Seo\SearchConsoleSyncClient;
use SsSystems\Platform\Seo\SearchConsoleWriter as SearchConsoleWriterContract;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Per-request SEO state accumulator (consumed by app layout).
        $this->app->singleton(SEOBuilder::class);

        // The shared kit's Search Console interfaces, bound to this site's
        // own implementations: GoogleSearchConsoleService already carries
        // everything SearchConsoleClient needs (SitemapStatus's resolution
        // of it keeps working unchanged) and now everything the fuller
        // SearchConsoleSyncClient needs too, and
        // SsSystems\Platform\Seo\SearchConsoleSync::run() writes through
        // App\Support\Seo\SearchConsoleWriter — see
        // App\Console\Commands\SyncGoogleSearchConsole for the command that
        // drives them.
        $this->app->bind(SearchConsoleClient::class, GoogleSearchConsoleService::class);
        $this->app->bind(SearchConsoleSyncClient::class, GoogleSearchConsoleService::class);
        $this->app->bind(SearchConsoleWriterContract::class, SiteSearchConsoleWriter::class);
        // Bing Webmaster Tools, the same way (kit 0.8.0): the kit's sync, this
        // site's writer, the client built from this site's key.
        $this->app->bind(KitBingWriter::class, BingWriter::class);
        $this->app->bind(BingWebmasterClient::class, BingWebmasterService::class);

        // The shared kit's ten SEO report generators (SsSystems\Platform\
        // Reports\*, ported verbatim from this app's own app/Console/
        // Commands/Seo*.php — see vendor/ss-systems/platform-kit/docs/
        // REPORTS-PORTING.md) read only these nine small interfaces, never
        // Eloquent/Http/Storage/config() directly. This site binds every
        // one of them (App\Support\Seo\Reports\ReportCapabilities::
        // provided() names the same nine keys), so all ten reports are
        // available here; a site that cannot provide one (e.g.
        // jpeterson-design's AreaCatalog) simply never binds it and that
        // report reads as "not available" instead of throwing.
        $this->app->bind(QueryMetricsReader::class, EloquentQueryMetricsReader::class);
        $this->app->bind(PsiSnapshotReader::class, EloquentPsiSnapshotReader::class);
        $this->app->bind(PageFetcher::class, HttpPageFetcher::class);
        $this->app->bind(SiteCatalog::class, HttpSiteCatalog::class);
        $this->app->bind(SiteIdentity::class, ConfigSiteIdentity::class);
        $this->app->bind(AreaCatalog::class, EloquentAreaCatalog::class);
        $this->app->bind(HealthDataReader::class, EloquentHealthDataReader::class);
        $this->app->bind(ClarityMetricsReader::class, EloquentClarityMetricsReader::class);
        $this->app->bind(CacheInterface::class, LaravelSimpleCache::class);

        // The kit's URL-Inspection sweep (SsSystems\Platform\Seo\Inspection\
        // UrlInspectionSweep — see seo:gsc-inspect-bulk, now a thin wrapper
        // over it, and docs/INSPECTION-SWEEP.md in the kit's source repo)
        // reads only these four small interfaces, never Eloquent/the
        // filesystem/config() directly.
        $this->app->bind(UrlInspectorContract::class, SearchConsoleUrlInspector::class);
        $this->app->bind(SitemapSourceContract::class, FileSitemapSource::class);
        $this->app->bind(CoverageStoreContract::class, EloquentCoverageStore::class);
        $this->app->bind(TrackedPathsContract::class, TrackedPathsFromModel::class);

        // A factory, not a singleton: the key prefix is baked in at
        // resolution time from the CURRENT tenant (Tenancy::cacheKey()), and
        // a queue worker or the parallel test suite can resolve this for more
        // than one site inside one process — a singleton would freeze the
        // first tenant's prefix for every one after it. Same daily/per-minute
        // limits and the same per-tenant key shape
        // (Tenancy::cacheKey('gsc.url-inspection'), bare for the default
        // site) as the retired App\Support\Seo\UrlInspectionQuota static
        // class this replaces — GscErrorController now reads this instance
        // instead of that class's static methods.
        $this->app->bind(KitUrlInspectionQuota::class, fn ($app) => new KitUrlInspectionQuota(
            cache: $app->make(CacheInterface::class),
            keyPrefix: Tenancy::cacheKey('gsc.url-inspection'),
            dailyLimitValue: (int) config('services.google.search_console.inspection_daily_quota', 2000),
            perMinuteLimitValue: (int) config('services.google.search_console.inspection_per_minute_quota', 600),
        ));

        // Site Pulse (SsSystems\Platform\Pulse, kit 0.10.0) — first-party
        // usage telemetry feeding ss-systems' seo/snapshot 'pulse' card
        // (App\Http\Controllers\Api\Admin\V1\SeoReportController::
        // pulseSnapshot()). Both the Recorder (writes, from the /t beacon)
        // and the SnapshotBuilder (reads, per admin request) get their OWN
        // DatabaseTableStorage over the same 'site_events' table, scoped by
        // a CLOSURE reading Tenancy::currentId() at call time — not a
        // captured value — since these are singletons a queue worker or the
        // parallel test suite can resolve once and then use across more
        // than one tenant via Tenancy::for()/::each().
        $this->app->singleton(Recorder::class, function () {
            return new Recorder(
                storage: new DatabaseTableStorage(
                    DB::connection(), tenantColumn: 'site_id', tenantId: fn () => Tenancy::currentId(),
                ),
                events: ['page', 'gallery', 'before_after', 'call', 'email', 'jserr'],
            );
        });

        $this->app->singleton(SnapshotBuilder::class, function () {
            return new SnapshotBuilder(
                storage: new DatabaseTableStorage(
                    DB::connection(), tenantColumn: 'site_id', tenantId: fn () => Tenancy::currentId(),
                ),
                events: ['page', 'gallery', 'before_after', 'call', 'email', 'jserr'],
                options: [
                    'timezone' => 'America/Chicago',
                    // No 'search_event': neither gsc nor jpeterson has a
                    // search feature, so searches/searched_cities/filters
                    // are correctly OMITTED from the snapshot, not zeroed.
                    'feature_labels' => [
                        'gallery' => 'Galleries opened',
                        'before_after' => 'Before/after used',
                    ],
                ],
            );
        });

        // BeaconController itself needs the app key at construction time —
        // built here rather than left to autowiring, which has no way to
        // supply the plain string $appKey constructor argument.
        $this->app->bind(BeaconController::class, fn ($app) => new BeaconController(
            $app->make(Recorder::class),
            (string) config('app.key'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // /areas-served/{area}: a served town binds as usual; a town the site
        // no longer serves 301s to its nearest served neighbour (same spoke)
        // or the areas index — from the binding itself, since a failed
        // implicit binding 404s before any middleware could redirect, and
        // Google keeps re-crawling those old pages as "Not found (404)".
        Route::bind('area', function (string $value, $route) {
            $path = '/'.ltrim((string) request()->path(), '/');
            $public = (bool) preg_match('#^/(?:areas-served|areas|locations)/#', $path);

            // Anything but the public area pages — the admin API's
            // `areas/{area}` takes the raw id (int $area), nothing binds —
            // gets the parameter untouched, as before this binding existed.
            if (! $public) {
                return $value;
            }

            if ($area = AreaServed::query()->where('slug', $value)->first()) {
                return $area;
            }
            $suffix = preg_match('#^/(?:areas-served|areas|locations)/[^/]+(/.*)$#', $path, $m) ? $m[1] : '';
            $target = RetiredAreaRedirect::target($value, $suffix);
            if ($target !== null) {
                throw new HttpResponseException(redirect($target, 301));
            }
            abort(404);
        });

        // This site's own Google OAuth client, entered from the admin,
        // overlays the env fallback for Business Profile and Search Console.
        GoogleOAuthApp::apply();
        GoogleBusinessListing::apply();

        // Dev guardrail (same as hive2025): surface N+1 lazy loads in the log
        // during development without ever breaking a page — and never in
        // production, where an unexpected lazy load must degrade, not throw.
        if (! app()->isProduction()) {
            Model::preventLazyLoading();
            Model::handleLazyLoadingViolationUsing(
                function ($model, string $relation): void {
                    logger()->warning('Lazy load: '.$model::class.'::'.$relation);
                }
            );
        }

        // Record when the AI feeds were last rebuilt, in the DATABASE rather
        // than leaving the dashboard to read file mtimes.
        //
        // The GEO card used to stat public/llms.txt on whichever machine
        // rendered the page — so browsing the admin locally reported "STALE —
        // 1 month ago" while production had regenerated it that morning. A
        // monitor that cries wolf in dev is a monitor people learn to ignore.
        // The stamp travels with the DB (dev pulls production), so the card
        // reports whether the JOB ran, which is the actual question.
        Event::listen(
            CommandFinished::class,
            function ($event): void {
                if ($event->command !== 'geo:llms-txt' || $event->exitCode !== 0) {
                    return;
                }

                try {
                    Tenancy::table('platform_settings')->updateOrInsert(
                        ['site_id' => Site::current()?->id, 'key' => 'geo.llms_txt_generated_at'],
                        ['value' => now()->toIso8601String(), 'updated_at' => now(), 'created_at' => now()],
                    );
                } catch (\Throwable) {
                    // Never let bookkeeping fail a generation run.
                }
            }
        );

        // Livewire update requests POST to /livewire/update and do NOT re-run
        // the original route's middleware. Without this, ResolveAdminSite never
        // fires on interaction, so every admin action after first paint would
        // run against the DEFAULT site instead of the one in the URL.
        Livewire::addPersistentMiddleware([
            ResolveSite::class,
            ResolveAdminSite::class,
        ]);

        // Any save/delete of public content schedules a debounced sitemap
        // regeneration + WebSub ping, so honest lastmod values reach crawlers
        // in minutes instead of waiting for the nightly cycle.
        $recrawlNudge = function ($model): void {
            RecrawlNudger::nudge();
        };
        foreach ([
            AreaServed::class,
            Project::class,
            ProjectImage::class,
            Testimonial::class,
            LandingPage::class,
        ] as $model) {
            $model::saved($recrawlNudge);
            $model::deleted($recrawlNudge);
        }

        RateLimiter::for('gemini-ai-content', function (): array {
            $rpmLimit = max(1, (int) env('GOOGLE_GEMINI_RPM_LIMIT', 10));

            return [Limit::perMinute($rpmLimit)->by('gemini-global')];
        });

        if (app()->environment('production') && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        $this->applySocialUrlOverrides();

        // Register IndexNow observers for automatic URL submission
        // Anything a crawler reads from a generated file (sitemaps, llms.txt)
        // refreshes itself after the response whenever listed content
        // changes — one run per request, however many saves. The observers
        // below still rebuild the sitemap synchronously for the few events
        // they always did; this covers everything else (areas, services,
        // posts, landing pages) and the llms files.
        foreach ([
            Project::class, ProjectImage::class, AreaServed::class, Testimonial::class,
            Service::class, BlogPost::class, LandingPage::class,
        ] as $model) {
            $model::saved(fn () => PublicFeeds::refreshSoon());
            $model::deleted(fn () => PublicFeeds::refreshSoon());
        }

        Testimonial::observe(TestimonialObserver::class);
        AreaServed::observe(AreaServedObserver::class);
        Project::observe(ProjectObserver::class);
        BlogPost::observe(BlogPostObserver::class);
        ProjectImage::observe(ProjectImageObserver::class);

        // Restrict Log Viewer access to specific admin emails only.
        // Read via config() (not env()) so it keeps working under config:cache.
        $allowedEmails = array_filter(array_map('trim', explode(',', (string) config('log-viewer.allowed_emails', 'patryk@gs.construction'))));

        LogViewer::auth(function (Request $request) use ($allowedEmails) {
            if (app()->environment(['local', 'testing'])) {
                return true;
            }

            $productionToken = trim((string) config('log-viewer.production_token', ''));

            if ($productionToken !== '' && hash_equals($productionToken, (string) $request->bearerToken())) {
                return true;
            }

            // The central admin (ss.systems) reads this site's logs
            // server-to-server with its own dedicated token, separate from
            // production_token so either can be rotated alone.
            $hubToken = trim((string) config('log-viewer.hub_token', ''));

            if ($hubToken !== '' && hash_equals($hubToken, (string) $request->bearerToken())) {
                return true;
            }

            $user = $request->user();

            return $user && in_array($user->email, $allowedEmails, true);
        });

        Gate::define('viewLogViewer', function ($user) {
            if (app()->environment(['local', 'testing'])) {
                return true;
            }

            return $user && in_array($user->email, $allowedEmails, true);
        });

        // Optimize anonymous Blade components with Livewire Blaze
        // (register general path first, then specific overrides — Blaze uses most-specific match)
        Blaze::optimize()
            ->in(resource_path('views/components'))
            ->in(resource_path('views/components/layouts'), compile: false);
    }

    private function applySocialUrlOverrides(): void
    {
        try {
            if (! Schema::hasTable('platform_settings')) {
                return;
            }

            foreach (['instagram', 'google', 'facebook', 'yelp', 'houzz', 'angi'] as $platform) {
                $override = PlatformSetting::get('socials.url.'.$platform);
                if (is_string($override) && $override !== '') {
                    config()->set('socials.'.$platform.'.url', $override);
                }
            }
        } catch (\Throwable) {
            // During install/migrate, settings table may be unavailable.
        }
    }
}
