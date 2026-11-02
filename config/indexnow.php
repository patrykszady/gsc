<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto-Submit on Model Events
    |--------------------------------------------------------------------------
    |
    | Automatically submit URLs to IndexNow when models are created, updated,
    | or deleted (observers + App\Jobs\SubmitUrlsToIndexNow). Set to false to
    | only submit manually — e.g. via `php artisan indexnow:submit` or the
    | scheduled `seo:indexnow` sweep, neither of which reads this flag.
    |
    | The key/host/endpoint this file used to hold moved to
    | services.indexnow.key (App\Support\Seo\IndexNowSiteKey, kit 0.19.4) —
    | config('services.indexnow.key')/env INDEXNOW_KEY, else a platform
    | setting generated on first use, so no key is configured here by hand.
    |
    */
    'auto_submit' => env('INDEXNOW_AUTO_SUBMIT', true),

];
