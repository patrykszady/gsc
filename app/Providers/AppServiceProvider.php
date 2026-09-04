<?php

namespace App\Providers;

use App\Models\AreaServed;
use App\Models\PlatformSetting;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\Testimonial;
use App\Observers\AreaServedObserver;
use App\Observers\ProjectImageObserver;
use App\Observers\ProjectObserver;
use App\Observers\TestimonialObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Illuminate\Support\ServiceProvider;
use Livewire\Blaze\Blaze;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Per-request SEO state accumulator (consumed by app layout).
        $this->app->singleton(\App\Support\SEO\SEOBuilder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Dev guardrail (same as hive2025): surface N+1 lazy loads in the log
        // during development without ever breaking a page — and never in
        // production, where an unexpected lazy load must degrade, not throw.
        if (! app()->isProduction()) {
            \Illuminate\Database\Eloquent\Model::preventLazyLoading();
            \Illuminate\Database\Eloquent\Model::handleLazyLoadingViolationUsing(
                function ($model, string $relation): void {
                    logger()->warning('Lazy load: ' . $model::class . '::' . $relation);
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
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Console\Events\CommandFinished::class,
            function ($event): void {
                if ($event->command !== 'geo:llms-txt' || $event->exitCode !== 0) {
                    return;
                }

                try {
                    \App\Support\Tenancy::table('platform_settings')->updateOrInsert(
                        ['site_id' => \App\Models\Site::current()?->id, 'key' => 'geo.llms_txt_generated_at'],
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
            \App\Http\Middleware\ResolveSite::class,
            \App\Http\Middleware\ResolveAdminSite::class,
        ]);

        // Any save/delete of public content schedules a debounced sitemap
        // regeneration + WebSub ping, so honest lastmod values reach crawlers
        // in minutes instead of waiting for the nightly cycle.
        $recrawlNudge = function ($model): void {
            \App\Support\SEO\RecrawlNudger::nudge();
        };
        foreach ([
            \App\Models\AreaServed::class,
            \App\Models\Project::class,
            \App\Models\ProjectImage::class,
            \App\Models\Testimonial::class,
            \App\Models\LandingPage::class,
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
        Testimonial::observe(TestimonialObserver::class);
        AreaServed::observe(AreaServedObserver::class);
        Project::observe(ProjectObserver::class);
        \App\Models\BlogPost::observe(\App\Observers\BlogPostObserver::class);
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
                $override = PlatformSetting::get('socials.url.' . $platform);
                if (is_string($override) && $override !== '') {
                    config()->set('socials.' . $platform . '.url', $override);
                }
            }
        } catch (\Throwable) {
            // During install/migrate, settings table may be unavailable.
        }
    }
}
