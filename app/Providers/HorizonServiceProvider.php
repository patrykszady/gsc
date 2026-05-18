<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if (app()->environment('local')) {
                return true;
            }

            // Use a dedicated Horizon allowlist when provided; otherwise
            // fall back to the same admin email allowlist as Log Viewer.
            $horizonEmails = array_filter(array_map(
                'trim',
                explode(',', (string) env('HORIZON_AUTH_EMAILS', ''))
            ));

            $adminEmails = array_filter(array_map(
                'trim',
                explode(',', (string) env('LOG_VIEWER_ALLOWED_EMAILS', 'patryk@gs.construction'))
            ));

            $allowedEmails = ! empty($horizonEmails) ? $horizonEmails : $adminEmails;

            return $user && in_array($user->email, $allowedEmails, true);
        });
    }
}
