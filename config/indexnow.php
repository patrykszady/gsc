<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IndexNow Enabled
    |--------------------------------------------------------------------------
    |
    | This option controls whether IndexNow submissions are enabled. When
    | disabled, no URLs will be submitted to search engines.
    |
    */
    'enabled' => env('INDEXNOW_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | IndexNow API Key
    |--------------------------------------------------------------------------
    |
    | Your IndexNow API key. This should be a unique string that you generate.
    | The key must only contain lowercase a-z, 0-9, and dashes. It should be
    | between 8 and 128 characters long.
    |
    | Generate a key: You can use any UUID or random string generator.
    | Example: bin2hex(random_bytes(16)) in PHP
    |
    */
    'key' => env('INDEXNOW_KEY'),

    /*
    |--------------------------------------------------------------------------
    | IndexNow Endpoint
    |--------------------------------------------------------------------------
    |
    | The IndexNow API endpoint to submit URLs to. You can use any of these:
    | - https://api.indexnow.org/indexnow (default - routes to all engines)
    | - https://www.bing.com/indexnow
    | - https://yandex.com/indexnow
    | - https://search.seznam.cz/indexnow
    | - https://searchadvisor.naver.com/indexnow
    |
    */
    'endpoint' => env('INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow'),

    /*
    |--------------------------------------------------------------------------
    | Auto-Submit on Model Events
    |--------------------------------------------------------------------------
    |
    | Automatically submit URLs to IndexNow when models are created, updated,
    | or deleted. Set to false to only submit manually via the Artisan command.
    |
    */
    'auto_submit' => env('INDEXNOW_AUTO_SUBMIT', true),

];
