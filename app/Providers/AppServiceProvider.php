<?php

namespace App\Providers;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Testimonial;
use App\Observers\AreaServedObserver;
use App\Observers\ProjectObserver;
use App\Observers\TestimonialObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register IndexNow observers for automatic URL submission
        Testimonial::observe(TestimonialObserver::class);
        AreaServed::observe(AreaServedObserver::class);
        Project::observe(ProjectObserver::class);

        // Allow authenticated admin users to access Log Viewer in production
        Gate::define('viewLogViewer', function ($user) {
            return $user !== null;
        });
    }
}
