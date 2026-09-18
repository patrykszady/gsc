<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Social automation timezone
    |--------------------------------------------------------------------------
    | Wall-clock timezone App\Services\Social\AutomationPlanner computes and
    | displays each site's weekly posting plan in, and the timezone
    | App\Console\Commands\SocialAutomationTick reads "now" in when deciding
    | what is due. Matches every social-posting schedule entry this replaced.
    |
    | Like any other shared config file, a site can override this via
    | config/sites/{slug}/social-automation.php (App\Support\SiteConfig) if a
    | future tenant ever needs a different posting timezone.
    */
    'timezone' => env('SOCIAL_AUTOMATION_TIMEZONE', 'America/Chicago'),

];
