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
        'analytics_id' => env('GOOGLE_ANALYTICS_ID'),
    ],

    'microsoft' => [
        'clarity_id' => env('MICROSOFT_CLARITY_ID'),
    ],

    'mailtrap' => [
        'api_key' => env('MAILTRAP_API_KEY'),
        'inbox_id' => env('MAILTRAP_INBOX_ID'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
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
