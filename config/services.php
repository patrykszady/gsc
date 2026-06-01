<?php

// Compose the IPRoyal residential proxy URL from discrete env pieces. Used by
// both `services.scraper.proxy` and `services.yelp.business.proxy` (the Yelp
// services overwrite `session-placeholder` per launch via
// forceUniqueProxySession()). Returns null when not configured.
$composeIproyalProxy = static function (): ?string {
    if ($override = env('SCRAPER_PROXY_URL')) {
        return (string) $override;
    }
    $user = (string) env('IPROYAL_USERNAME', '');
    $pass = (string) env('IPROYAL_PASSWORD', '');
    $host = (string) env('IPROYAL_HOST', 'geo.iproyal.com');
    $port = (string) env('IPROYAL_PORT', '12321');
    if ($user === '' || $pass === '' || $host === '') return null;
    $flags = [];
    if ($c = env('IPROYAL_COUNTRY'))    $flags[] = 'country-' . $c;
    if ($city = env('IPROYAL_CITY'))    $flags[] = 'city-' . $city;
    if (env('IPROYAL_STREAMING', true)) $flags[] = 'streaming-1';
    $flags[] = 'session-placeholder';
    $flags[] = 'lifetime-' . env('IPROYAL_LIFETIME', '30m');
    return sprintf('http://%s:%s_%s@%s:%s', $user, $pass, implode('_', $flags), $host, $port);
};

// IPRoyal ISP (static) proxies. IPROYAL_ISP_POOL is a comma/newline-separated
// list of `host:port` pairs (or full URLs). Username/password are shared.
$composeIproyalIspPool = static function (): array {
    $raw  = (string) env('IPROYAL_ISP_POOL', '');
    $user = (string) env('IPROYAL_ISP_USERNAME', '');
    $pass = (string) env('IPROYAL_ISP_PASSWORD', '');
    if ($raw === '' || $user === '' || $pass === '') return [];
    $items = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $raw)) ?: []));
    $out = [];
    foreach ($items as $item) {
        if (str_contains($item, '://')) { $out[] = $item; continue; }
        $out[] = sprintf('http://%s:%s@%s', $user, $pass, $item);
    }
    return $out;
};

// 2captcha residential proxy (rotating). Single endpoint, rotates exit IP per
// request unless `-session-X` is appended to the username.
$compose2captchaProxy = static function (): ?string {
    $user = (string) env('CAPTCHA_PROXY_USERNAME', '');
    $pass = (string) env('CAPTCHA_PROXY_PASSWORD', '');
    $host = (string) env('CAPTCHA_PROXY_HOST', '');
    if ($user === '' || $pass === '' || $host === '') return null;
    return sprintf('http://%s:%s@%s', $user, $pass, $host);
};

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

    'hive' => [
        'url' => env('HIVE_API_URL', 'https://hive.contractors'),
        'token' => env('HIVE_API_TOKEN'),
        'cache_ttl' => (int) env('HIVE_API_CACHE_TTL', 21600), // 6h
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
        'analytics_id' => env('GOOGLE_ANALYTICS_GTAG', env('GOOGLE_MEASUREMENT_ID')),
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
            'production_url' => env('APP_URL', 'https://gs.construction'),
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
        'site_url' => env('APP_URL', 'https://gs.construction'),
    ],

    'microsoft' => [
        // Client-side Clarity project ID (used for JS snippet rendering).
        'clarity_id' => env('MICROSOFT_CLARITY_ID'),
        // Server-side Clarity API sync (seo:clarity-sync).
        'clarity' => [
            'project_id' => env('MICROSOFT_CLARITY_ID'),
            'api_token' => env('MICROSOFT_CLARITY_API_TOKEN'),
            'base_url' => env('MICROSOFT_CLARITY_API_BASE_URL', 'https://www.clarity.ms/export-data/api/v1'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta (Instagram + Facebook) Social Publishing
    |--------------------------------------------------------------------------
    |
    | Graph API app credentials. Page Access Token, Page ID, and Instagram
    | Business ID are obtained via OAuth and stored in the oauth_tokens
    | table (provider='meta'). Connect at /admin/platforms.
    |
    */
    'meta' => [
        'enabled' => true,
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'page_access_token' => env('META_PAGE_ACCESS_TOKEN'),
        'facebook_page_id' => env('META_FACEBOOK_PAGE_ID'),
        'facebook_place_id' => env('META_FACEBOOK_PLACE_ID'),
        'instagram_account_id' => env('META_INSTAGRAM_ACCOUNT_ID'),
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
        'proxy' => $composeIproyalProxy(),
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
            // Per-script Chromium budget (ms). Must accommodate the worst
            // case: DataDome JS self-resolve loop (~60s) + 2captcha solve
            // (~30s) + cookie inject + reload + actual upload. 240s gives
            // PHP a 270s wall-clock budget (timeout_ms/1000 + 30).
            'timeout_ms' => (int) env('YELP_BIZ_TIMEOUT_MS', 240000),
            // Global Redis lock to enforce one Yelp browser automation at a time.
            // This protects production nodes from overlapping Chromium sessions.
            // Keep TTL slightly above the Horizon worker timeout (360s)
            // so a SIGKILL'd worker's lock self-clears within ~30s of the
            // timeout instead of blocking the queue for the full TTL.
            'automation_lock_ttl_seconds' => (int) env('YELP_BIZ_AUTOMATION_LOCK_TTL', 390),
            // How long a job waits for the lock before giving up and
            // releasing itself back to the queue. With maxProcesses=1 only
            // ONE worker exists for this queue — blocking it on the lock is
            // pointless because no other worker can free the lock. Keep this
            // short (5s) so a busy lock immediately releases the job back to
            // the queue with the throttle back-off, freeing the worker to
            // pick up something else / wait out the back-off.
            'automation_lock_wait_seconds' => (int) env('YELP_BIZ_AUTOMATION_LOCK_WAIT', 5),
            // Hard throttle between Yelp Chromium launches across the whole
            // host. 5s default = brief breath between uploads; tune via env
            // if Yelp starts rate-limiting.
            'min_interval_seconds' => (int) env('YELP_BIZ_MIN_INTERVAL_SECONDS', 5),
            // Override where Puppeteer looks for its installed Chrome.
            // Defaults to {real-$HOME}/.cache/puppeteer. Set this only if
            // your deploy installs Chrome at a non-standard location.
            'puppeteer_cache_dir' => env('PUPPETEER_CACHE_DIR'),
            // YELP_BIZ_PROXY may be a single URL or a comma/newline-separated
            // list of URLs. When multiple are provided, one is picked at
            // random per process — gives us automatic fallback between
            // dedicated ISP IPs without a code change when one gets flagged.
            'proxy' => (function () use ($composeIproyalProxy, $composeIproyalIspPool, $compose2captchaProxy) {
                $raw = (string) env('YELP_BIZ_PROXY', '');
                if ($raw !== '') {
                    $list = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $raw)) ?: []));
                    if (count($list) > 0) return $list[array_rand($list)];
                }
                if ($p = $compose2captchaProxy()) return $p;
                $isp = $composeIproyalIspPool();
                if (count($isp) > 0) return $isp[array_rand($isp)];
                return $composeIproyalProxy();
            })(),
            // Full list (debug / admin UI display).
            'proxy_pool' => (function () use ($composeIproyalProxy, $composeIproyalIspPool, $compose2captchaProxy) {
                $raw = (string) env('YELP_BIZ_PROXY', '');
                if ($raw !== '') {
                    return array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $raw)) ?: []));
                }
                $pool = [];
                if ($p = $compose2captchaProxy()) $pool[] = $p;
                $pool = array_merge($pool, $composeIproyalIspPool());
                if ($p = $composeIproyalProxy()) $pool[] = $p;
                return $pool;
            })(),
            // Optional pre-known biz_photos URL. Leave empty to auto-detect after login.
            'biz_photos_url' => env('YELP_BIZ_PHOTOS_URL'),

            // Public-facing Yelp page slug used to build deep links into
            // www.yelp.com/biz_photos/<slug>?select=<photo_id>. Defaults to
            // the Prospect Heights location.
            'public_biz_slug' => env('YELP_PUBLIC_BIZ_SLUG', 'gs-construction-prospect-heights'),

            // Remote-login viewer (Xvfb + x11vnc + noVNC + websockify) so the
            // admin can complete login / captcha / 2FA from the website on
            // a headless production server. Requires: xvfb, x11vnc, novnc,
            // websockify packages installed on the host.
            'remote_login' => [
                'enabled' => env('YELP_REMOTE_LOGIN_ENABLED', true),
                'display' => env('YELP_REMOTE_LOGIN_DISPLAY', ':99'),
                'screen' => env('YELP_REMOTE_LOGIN_SCREEN', '1280x800x24'),
                'vnc_port' => (int) env('YELP_REMOTE_LOGIN_VNC_PORT', 5999),
                // websockify port — must be reachable from the browser.
                // Default 0.0.0.0:6080. Put a TLS reverse proxy in front for HTTPS sites.
                'ws_host' => env('YELP_REMOTE_LOGIN_WS_HOST', '0.0.0.0'),
                'ws_port' => (int) env('YELP_REMOTE_LOGIN_WS_PORT', 6080),
                // Public URL noVNC will be reached at (e.g. https://gs.construction/yelp-vnc).
                // Leave null to build http://{request_host}:{ws_port} automatically.
                'public_url' => env('YELP_REMOTE_LOGIN_PUBLIC_URL'),
                // Path to noVNC web assets (vnc.html lives here). Ubuntu/Debian default:
                'novnc_web' => env('YELP_REMOTE_LOGIN_NOVNC_WEB', '/usr/share/novnc'),
                'xvfb_binary' => env('YELP_REMOTE_LOGIN_XVFB', 'Xvfb'),
                'x11vnc_binary' => env('YELP_REMOTE_LOGIN_X11VNC', 'x11vnc'),
                'websockify_binary' => env('YELP_REMOTE_LOGIN_WEBSOCKIFY', 'websockify'),
                // Session is auto-killed after this many seconds in case the user
                // walks away without clicking Stop.
                'max_ttl_seconds' => (int) env('YELP_REMOTE_LOGIN_MAX_TTL', 1200),
            ],
        ],
    ],

    'cloudflare' => [
        // Zone ID: Cloudflare dashboard → <zone> overview → right-hand panel → Zone ID
        'zone_id'   => env('CLOUDFLARE_ZONE_ID'),
        // API Token: must have "Cache Purge" permission scoped to this zone
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Instagram (Puppeteer / web UI fallback)
    |--------------------------------------------------------------------------
    |
    | Used by the hybrid publish flow that adds location tags via the IG web
    | UI after Graph API publish. Also powers the /admin/platforms remote
    | login viewer so an operator can re-authenticate the persistent
    | Chromium profile when IG invalidates the session.
    |
    */
    'instagram' => [
        'user_data_dir' => env('INSTAGRAM_USER_DATA_DIR', storage_path('app/instagram-puppeteer')),
        'node_binary' => env('INSTAGRAM_NODE_BINARY', 'node'),
        'remote_login' => [
            'enabled' => env('INSTAGRAM_REMOTE_LOGIN_ENABLED', true),
            'display' => env('INSTAGRAM_REMOTE_LOGIN_DISPLAY', ':98'),
            'screen' => env('INSTAGRAM_REMOTE_LOGIN_SCREEN', '1366x900x24'),
            // Distinct ports from Yelp (5999/6080) so both can run side-by-side.
            'vnc_port' => (int) env('INSTAGRAM_REMOTE_LOGIN_VNC_PORT', 5998),
            'ws_host' => env('INSTAGRAM_REMOTE_LOGIN_WS_HOST', '0.0.0.0'),
            'ws_port' => (int) env('INSTAGRAM_REMOTE_LOGIN_WS_PORT', 6081),
            'public_url' => env('INSTAGRAM_REMOTE_LOGIN_PUBLIC_URL'),
            'novnc_web' => env('INSTAGRAM_REMOTE_LOGIN_NOVNC_WEB', '/usr/share/novnc'),
            'xvfb_binary' => env('INSTAGRAM_REMOTE_LOGIN_XVFB', 'Xvfb'),
            'x11vnc_binary' => env('INSTAGRAM_REMOTE_LOGIN_X11VNC', 'x11vnc'),
            'websockify_binary' => env('INSTAGRAM_REMOTE_LOGIN_WEBSOCKIFY', 'websockify'),
            'max_ttl_seconds' => (int) env('INSTAGRAM_REMOTE_LOGIN_MAX_TTL', 1500),
        ],
    ],

];
