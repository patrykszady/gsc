<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'places_api_key' => env('GOOGLE_PLACES_API_KEY'),
        'analytics_id' => env('GOOGLE_ANALYTICS_GTAG', env('GOOGLE_MEASUREMENT_ID', env('GOOGLE_ANALYTICS_ID'))),
        'ads_id' => env('GOOGLE_ADS_ID'),
        'ads_conversions' => [
            'form' => env('GOOGLE_ADS_CONVERSION_FORM'),
            'phone' => env('GOOGLE_ADS_CONVERSION_PHONE'),
            'email' => env('GOOGLE_ADS_CONVERSION_EMAIL'),
            'call' => env('GOOGLE_ADS_CONVERSION_CALL'),
            'lead' => env('GOOGLE_ADS_CONVERSION_LEAD'),
        ],
        'measurement_id' => env('GOOGLE_MEASUREMENT_ID'), // GA4 Measurement ID (G-XXXXXXXXXX)
        'measurement_api_secret' => env('GOOGLE_MEASUREMENT_API_SECRET'), // GA4 Measurement Protocol API Secret
        'gemini_api_key' => env('GOOGLE_GEMINI_API_KEY'),
        'gemini_model' => env('GOOGLE_GEMINI_MODEL', 'gemini-2.0-flash'),
        'business_profile' => [
            'enabled' => env('GOOGLE_BUSINESS_PROFILE_ENABLED', false),
            'client_id' => env('GOOGLE_BUSINESS_PROFILE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET'),
            'refresh_token' => env('GOOGLE_BUSINESS_PROFILE_REFRESH_TOKEN'),
            'account_id' => env('GOOGLE_BUSINESS_PROFILE_ACCOUNT_ID'),
            'location_id' => env('GOOGLE_BUSINESS_PROFILE_LOCATION_ID'),
            'place_id' => env('GOOGLE_BUSINESS_PROFILE_PLACE_ID'),
            'production_url' => env('GOOGLE_BUSINESS_PROFILE_PRODUCTION_URL', 'https://gs.construction'),
            // Inject EXIF GPS into uploaded GBP photos. Coordinates come from
            // the project's matching AreaServed row; photos without a matching
            // area are uploaded without GPS rather than using a global fallback.
            'geotag_photos' => env('GBP_GEOTAG_PHOTOS', true),
            // Automatically geocode project cities via OpenStreetMap Nominatim
            // when projects are created or their location is changed.
            'auto_geocode_on_project_save' => env('GBP_AUTO_GEOCODE_ON_PROJECT_SAVE', true),
            'geocode_state' => env('GBP_GEOCODE_STATE', 'IL'),
            'geocode_country' => env('GBP_GEOCODE_COUNTRY', 'USA'),
        ],
        // Google Search Console API (free, official). Separate OAuth client
        // because the scope differs (webmasters.readonly). Reuses the same
        // Google Cloud project credentials if you wish (set the same client_id/secret).
        'search_console' => [
            'enabled' => env('GOOGLE_SEARCH_CONSOLE_ENABLED', false),
            'client_id' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_ID', env('GOOGLE_BUSINESS_PROFILE_CLIENT_ID')),
            'client_secret' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET', env('GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET')),
            'refresh_token' => env('GOOGLE_SEARCH_CONSOLE_REFRESH_TOKEN'),
            // Defaults to the existing seo.search_console.site_url (likely
            // `sc-domain:gs.construction`) to avoid configuring the same value twice.
            'site_url' => env('GOOGLE_SEARCH_CONSOLE_SITE_URL',
                env('GSC_SEARCH_CONSOLE_SITE_URL', 'sc-domain:gs.construction')),
        ],
        // PageSpeed Insights API (free, 25k req/day; API key recommended).
        'pagespeed' => [
            'api_key' => env('GOOGLE_PAGESPEED_API_KEY'),
            'strategies' => ['mobile', 'desktop'],
        ],
    ],

    // Bing Webmaster Tools API (free, simple API-key auth).
    'bing' => [
        'webmaster_api_key' => env('BING_WEBMASTER_API_KEY'),
        'site_url' => env('BING_WEBMASTER_SITE_URL', env('APP_URL', 'https://gs.construction')),
    ],

    'microsoft' => [
        'clarity_id' => env('MICROSOFT_CLARITY_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta (Instagram + Facebook) Social Publishing
    |--------------------------------------------------------------------------
    |
    | Graph API credentials for automated posting to Instagram Business
    | and Facebook Page. Both use the same Page Access Token.
    |
    | Setup: php artisan social:meta-auth
    |
    */
    'meta' => [
        'enabled' => env('META_SOCIAL_ENABLED', false),
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'page_access_token' => env('META_PAGE_ACCESS_TOKEN'),
        'facebook_page_id' => env('META_FACEBOOK_PAGE_ID'),
        'instagram_account_id' => env('META_INSTAGRAM_ACCOUNT_ID'),
        'production_url' => env('META_PRODUCTION_URL', 'https://gs.construction'),
    ],

    'mailtrap' => [
        'api_key' => env('MAILTRAP_API_KEY'),
        'inbox_id' => env('MAILTRAP_INBOX_ID'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'vision_model' => env('OPENAI_VISION_MODEL', 'gpt-4o'), // For image analysis
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile (Anti-Spam CAPTCHA)
    |--------------------------------------------------------------------------
    |
    | Cloudflare Turnstile is a privacy-friendly CAPTCHA alternative.
    | Get your keys from: https://dash.cloudflare.com/turnstile
    |
    */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'enabled' => env('TURNSTILE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alternate Domains Configuration
    |--------------------------------------------------------------------------
    |
    | SEO configuration for alternate domains that redirect to the main site.
    | Each domain has specific SEO focus, tracking source, and meta info.
    |
    */
    'domains' => [
        'primary' => env('APP_DOMAIN', 'gs.construction'),
        'alternates' => [
            'gsconstruction.design' => [
                'source' => 'design_domain',
                'seo_focus' => 'design',
                'title_prefix' => 'Kitchen & Bath Design',
                'description' => 'Award-winning kitchen and bathroom design services in Chicago. Transform your space with GS Construction\'s expert designers.',
                'keywords' => ['kitchen design', 'bathroom design', 'remodeling design', 'Chicago interior design', 'custom cabinetry design'],
            ],
            'gsconstruction.services' => [
                'source' => 'services_domain',
                'seo_focus' => 'services',
                'title_prefix' => 'Professional Remodeling Services',
                'description' => 'Full-service home remodeling in Chicago. Kitchen, bathroom, and whole-home renovation services by licensed contractors.',
                'keywords' => ['remodeling services', 'renovation services', 'Chicago contractors', 'home improvement', 'licensed remodelers'],
            ],
        ],
    ],

    'anticaptcha' => [
        'api_key' => env('ANTICAPTCHA_API_KEY'),
    ],

    'twocaptcha' => [
        'api_key' => env('TWOCAPTCHA_API_KEY'),
    ],

    'serpapi' => [
        'api_key' => env('SERPAPI_API_KEY', env('SERPAPI_KEY')),
        'yelp_place_id' => env('SERPAPI_YELP_PLACE_ID'),
        'google_data_id' => env('SERPAPI_GOOGLE_DATA_ID'),
    ],

    'scraper' => [
        'proxy' => env('SCRAPER_PROXY_URL'),
    ],

    'yelp' => [
        'business' => [
            'enabled' => true,
            'email' => env('YELP_BIZ_EMAIL'),
            'password' => env('YELP_BIZ_PASSWORD'),
            // Persistent Chromium profile dir so login/cookies survive between runs.
            'user_data_dir' => env('YELP_USER_DATA_DIR', storage_path('app/yelp-puppeteer')),
            // Path to node binary (override if not on PATH).
            'node_binary' => env('YELP_NODE_BINARY', 'node'),
            // Run Chromium headed for first-time login / debugging.
            'headed' => env('YELP_BIZ_HEADED', false),
            'timeout_ms' => (int) env('YELP_BIZ_TIMEOUT_MS', 180000),
            'proxy' => env('YELP_BIZ_PROXY', env('SCRAPER_PROXY_URL')),
        ],
    ],

    'cloudflare' => [
        // Zone ID: Cloudflare dashboard → <zone> overview → right-hand panel → Zone ID
        'zone_id'   => env('CLOUDFLARE_ZONE_ID'),
        // API Token: must have "Cache Purge" permission scoped to this zone
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
    ],

];
