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
use App\Support\GoogleOAuthApp;
use App\Support\PublicFeeds;
use App\Support\SEO\RecrawlNudger;
use App\Support\SEO\SEOBuilder;
use App\Support\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Blaze\Blaze;
use Livewire\Livewire;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Per-request SEO state accumulator (consumed by app layout).
        $this->app->singleton(SEOBuilder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // This site's own Google OAuth client, entered from the admin,
        // overlays the env fallback for Business Profile and Search Console.
        GoogleOAuthApp::apply();

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
