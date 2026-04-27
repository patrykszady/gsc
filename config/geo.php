<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Site Identity
    |--------------------------------------------------------------------------
    | Used in llms.txt, Organization schema, and AI feed metadata.
    */
    'site_name'        => env('GEO_SITE_NAME', 'GS Construction'),
    'site_description' => env('GEO_SITE_DESCRIPTION', 'Family-owned kitchen, bathroom, and home remodeling contractor serving the Chicago suburbs since 2015. 40+ years combined experience, 5-star rated, English & Polish.'),
    'site_url'         => env('GEO_SITE_URL', env('APP_URL', 'https://gs.construction')),

    /*
    |--------------------------------------------------------------------------
    | llms.txt Configuration
    |--------------------------------------------------------------------------
    | The llms.txt file is to AI crawlers what robots.txt is to web crawlers.
    | See: https://llmstxt.org
    */
    'llms_txt' => [
        'enabled'       => true,
        'max_products'  => 500,
        'cache_ttl'     => 3600,    // seconds
        'route'         => '/llms.txt',
        'full_route'    => '/llms-full.txt',   // extended version
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema / JSON-LD
    |--------------------------------------------------------------------------
    */
    // We already inject rich schema via <x-schema-org>; disable auto-injection
    // to avoid duplicate Organization / WebSite blocks.
    'schema' => [
        'auto_inject'      => false,
        'include_reviews'  => false,
        'include_faq'      => false,
        'include_breadcrumb' => false,
        'include_organization' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | GEO Scoring Thresholds
    |--------------------------------------------------------------------------
    */
    'scoring' => [
        'min_description_words'  => 100,
        'min_reviews_for_signal' => 1,
        'min_rating_for_signal'  => 4.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Product Feed
    |--------------------------------------------------------------------------
    */
    'feed' => [
        'enabled'       => true,
        'route'         => '/ai-product-feed.json',
        'sitemap_route' => '/ai-sitemap.xml',
        'cache_ttl'     => 900,
        'per_page'      => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Citation Engine
    |--------------------------------------------------------------------------
    */
    'citation' => [
        'inject_ratings'        => true,
        'inject_certifications' => true,
        'inject_awards'         => true,
        'inject_stats'          => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */
    'dashboard' => [
        'enabled'    => true,
        'path'       => env('GEO_DASHBOARD_PATH', '/geo'),

        /*
        | Auth middleware for the dashboard.
        | Set to null or 'none' to disable auth (dev only).
        | Examples: 'auth', 'auth:admin', 'auth:sanctum', 'auth:web'
        */
        'middleware' => env('GEO_DASHBOARD_MIDDLEWARE', 'auth'),

        /*
        | Which models to display in the Models page.
        | Each entry: ['model' => 'App\Models\Product', 'label' => 'Products']
        */
        'models' => [
            ['model' => \App\Models\Project::class,     'label' => 'Projects'],
            ['model' => \App\Models\Testimonial::class, 'label' => 'Testimonials'],
        ],
    ],
];
