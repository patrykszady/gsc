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
            'production_url' => env('GOOGLE_BUSINESS_PROFILE_PRODUCTION_URL', 'https://gs.construction'),
        ],
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

];
