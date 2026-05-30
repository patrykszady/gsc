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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Blaze\Blaze;

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
        ProjectImage::observe(ProjectImageObserver::class);

        // Restrict Log Viewer access to specific admin emails only
        Gate::define('viewLogViewer', function ($user) {
            $allowedEmails = array_filter(array_map('trim', explode(',', env('LOG_VIEWER_ALLOWED_EMAILS', 'patryk@gs.construction'))));

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
