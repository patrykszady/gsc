<?php

use RalphJSmit\Laravel\SEO\Models\SEO;

return [
    /**
     * The SEO model. You can use this setting to override the model used by the package.
     * Make sure to always extend the old model, so that you'll not lose functionality during upgrades.
     *
     * We use a custom model so that DB-stored admin overrides take precedence
     * over the model's getDynamicSEOData() defaults.
     */
    'model' => \App\Models\SeoOverride::class,

    /**
     * Use this setting to specify the site name that will be used in OpenGraph tags.
     */
    'site_name' => 'GS Construction & Remodeling',

    /**
     * Canonical business phone (E.164 or human-readable). Used by seo:gbp-parity
     * to verify NAP consistency across landing pages.
     */
    'phone' => env('BUSINESS_PHONE', '+1-224-735-4200'),

    /**
     * Address fragment that must appear on every key landing page (substring match,
     * case-insensitive). GS Construction is a service-area business with no
     * storefront, so we check for the brand service-area phrase rather than a street.
     */
    'address' => env('BUSINESS_ADDRESS_FRAGMENT', 'Chicagoland'),

    /**
     * Use this setting to specify the path to the sitemap of your website. This exact path will outputted, so
     * you can use both a hardcoded url and a relative path. We recommend the latter.
     *
     * Example: '/storage/sitemap.xml'
     * Do not forget the slash at the start. This will tell the search engine that the path is relative
     * to the root domain and not relative to the current URL. The `spatie/laravel-sitemap` package
     * is a great package to generate sitemaps for your application.
     */
    'sitemap' => '/sitemap.xml',

    /**
     * Use this setting to specify whether you want self-referencing `<link rel="canonical" href="$url">` tags to
     * be added to the head of every page. There has been some debate whether this a good practice, but experts
     * from Google and Yoast say that this is the best strategy.
     * See https://yoast.com/rel-canonical/.
     */
    'canonical_link' => true,

    'robots' => [
        /**
         * Use this setting to specify the default value of the robots meta tag. `<meta name="robots" content="noindex">`
         * Overwrite it with the robots attribute of the SEOData object. `SEOData->robots = 'noindex, nofollow'`
         * "max-snippet:-1" Use n chars (-1: Search engine chooses) as a search result snippet.
         * "max-image-preview:large" Max size of a preview in search results.
         * "max-video-preview:-1" Use max seconds (-1: There is no limit) as a video snippet in search results.
         * See https://developers.google.com/search/docs/advanced/robots/robots_meta_tag
         * Default: 'max-snippet:-1, max-image-preview:large, max-video-preview:-1'
         */
        'default' => 'max-snippet:-1,max-image-preview:large,max-video-preview:-1',

        /**
         * Force set the robots `default` value and make it impossible to overwrite it. (e.g. via SEOData->robots)
         * Use case: You need to set `noindex, nofollow` for the entire website without exception.
         * Default: false
         */
        'force_default' => false,
    ],

    /**
     * Use this setting to specify the path to the favicon for your website. The url to it will be generated using the `secure_url()` function,
     * so make sure to make the favicon accessibly from the `public` folder.
     *
     * You can use the following filetypes: ico, png, gif, jpeg, svg.
     */
    'favicon' => 'favicon.ico',

    'title' => [
        /**
         * Use this setting to let the package automatically infer a title from the url, if no other title
         * was given. This will be very useful on pages where you don't have an Eloquent model for, or where you
         * don't want to hardcode the title.
         *
         * For example, if you have a page with the url '/foo/about-me', we'll automatically set the title to 'About me' and append the site suffix.
         */
        'infer_title_from_url' => true,

        /**
         * Use this setting to provide a suffix that will be added after the title on each page.
         * If you don't want a suffix, you should specify an empty string.
         */
        'suffix' => '',

        /**
         * Use this setting to provide a custom title for the homepage. We will not use the suffix on the homepage,
         * so you'll need to add the suffix manually if you want that. If set to null, we'll determine the title
         * just like the other pages.
         */
        'homepage_title' => null,
    ],

    'description' => [
        /**
         * Use this setting to specify a fallback description, which will be used on places
         * where we don't have a description set via an associated ->seo model or via
         * the ->getDynamicSEOData() method.
         */
        'fallback' => 'Family-owned kitchen, bathroom, and home remodeling contractor serving the Chicago suburbs. 40+ years combined experience, 53+ five-star reviews.',
        /**
         * Use this setting to specify a fallback image, which will be used on places where you
         * don't have an image set via an associated ->seo model or via the ->getDynamicSEOData() method.
         * This should be a path to an image. The url to the path is generated using the `secure_url()` function
         * (`secure_url($yourProvidedPath)`), so make sure the image is accessible from the public folder.
         */
        'fallback' => 'images/og-default.jpg',
        /**
         * Use this setting to specify a fallback author, which will be used on places where you
         * don't have an author set via an associated ->seo model or via the ->getDynamicSEOData() method.
         */
        'fallback' => 'GS Construction',
        /**
         * Use this setting to enter your username and include that with the Twitter Card tags.
         * Enter the username like 'yourUserName', so without the '@'.
         */
        '@username' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rank tracker (SerpApi)
    |--------------------------------------------------------------------------
    |
    | Used by `seo:track-rankings` to monitor where GS Construction appears
    | on Google + Google Maps for the queries that matter most. Snapshots are
    | persisted in `seo_rank_snapshots` so we can chart movement over time.
    |
    | Patterns are matched (case-insensitive) against listing titles / domain
    | hosts to identify "us" — covers GBP name variants and the website host.
    */
    'rank_tracker' => [
        'identity_patterns' => [
            'gs construction',
            "greg's bathroom",
            'greg & son',
            'gs.construction',
        ],

        // Local competitors we track in the same SERP snapshot so we can chart
        // share-of-voice over time. Matched (case-insensitive) against listing
        // title or domain host. Update as the landscape shifts.
        'competitor_patterns' => [
            'airoom'                  => ['airoom'],
            'normandy_remodeling'     => ['normandy remodeling', 'normandyremodeling'],
            'lifestyle_renovations'   => ['lifestyle renovations', 'lifestylerenovations'],
            'jw_construction'         => ['jw construction'],
            'siena_construction'      => ['siena construction'],
            'sebring_design'          => ['sebring design', 'sebringdesignbuild'],
            're_bath'                 => ['re-bath', 'rebath'],
            'kitchen_master'          => ['kitchen master'],
            'remodeling_concepts'     => ['remodeling concepts'],
            'chicagoland_remodeling'  => ['chicagoland remodeling'],
        ],

        // Google web SERPs — uses SerpApi engine=google with `location` string.
        'web_queries' => [
            // Arlington Heights (HQ)
            ['q' => 'kitchen remodeling Arlington Heights IL',  'location' => 'Arlington Heights, Illinois, United States', 'city_slug' => 'arlington-heights'],
            ['q' => 'bathroom remodeling Arlington Heights IL', 'location' => 'Arlington Heights, Illinois, United States', 'city_slug' => 'arlington-heights'],
            ['q' => 'general contractor Arlington Heights IL',  'location' => 'Arlington Heights, Illinois, United States', 'city_slug' => 'arlington-heights'],
            ['q' => 'home remodeling Arlington Heights IL',     'location' => 'Arlington Heights, Illinois, United States', 'city_slug' => 'arlington-heights'],

            // Surrounding suburbs (priority growth markets)
            ['q' => 'kitchen remodeling Palatine IL',           'location' => 'Palatine, Illinois, United States',           'city_slug' => 'palatine'],
            ['q' => 'bathroom remodeling Palatine IL',          'location' => 'Palatine, Illinois, United States',           'city_slug' => 'palatine'],
            ['q' => 'general contractor Palatine IL',           'location' => 'Palatine, Illinois, United States',           'city_slug' => 'palatine'],
            ['q' => 'basement finishing Palatine IL',           'location' => 'Palatine, Illinois, United States',           'city_slug' => 'palatine'],
            ['q' => 'home additions Palatine IL',               'location' => 'Palatine, Illinois, United States',           'city_slug' => 'palatine'],
            ['q' => 'kitchen remodeling Mount Prospect IL',     'location' => 'Mount Prospect, Illinois, United States',     'city_slug' => 'mount-prospect'],
            ['q' => 'bathroom remodeling Mount Prospect IL',    'location' => 'Mount Prospect, Illinois, United States',     'city_slug' => 'mount-prospect'],
            ['q' => 'general contractor Mount Prospect IL',     'location' => 'Mount Prospect, Illinois, United States',     'city_slug' => 'mount-prospect'],
            ['q' => 'basement finishing Mount Prospect IL',     'location' => 'Mount Prospect, Illinois, United States',     'city_slug' => 'mount-prospect'],
            ['q' => 'home additions Mount Prospect IL',         'location' => 'Mount Prospect, Illinois, United States',     'city_slug' => 'mount-prospect'],
            ['q' => 'kitchen remodeling Schaumburg IL',         'location' => 'Schaumburg, Illinois, United States',         'city_slug' => 'schaumburg'],
            ['q' => 'bathroom remodeling Schaumburg IL',        'location' => 'Schaumburg, Illinois, United States',         'city_slug' => 'schaumburg'],
            ['q' => 'general contractor Schaumburg IL',         'location' => 'Schaumburg, Illinois, United States',         'city_slug' => 'schaumburg'],
            ['q' => 'basement finishing Schaumburg IL',         'location' => 'Schaumburg, Illinois, United States',         'city_slug' => 'schaumburg'],
            ['q' => 'home additions Schaumburg IL',             'location' => 'Schaumburg, Illinois, United States',         'city_slug' => 'schaumburg'],
            ['q' => 'kitchen remodeling Buffalo Grove IL',      'location' => 'Buffalo Grove, Illinois, United States',      'city_slug' => 'buffalo-grove'],
            ['q' => 'bathroom remodeling Buffalo Grove IL',     'location' => 'Buffalo Grove, Illinois, United States',      'city_slug' => 'buffalo-grove'],
            ['q' => 'general contractor Buffalo Grove IL',      'location' => 'Buffalo Grove, Illinois, United States',      'city_slug' => 'buffalo-grove'],
            ['q' => 'kitchen remodeling Barrington IL',         'location' => 'Barrington, Illinois, United States',         'city_slug' => 'barrington'],
            ['q' => 'bathroom remodeling Barrington IL',        'location' => 'Barrington, Illinois, United States',         'city_slug' => 'barrington'],
            ['q' => 'general contractor Barrington IL',         'location' => 'Barrington, Illinois, United States',         'city_slug' => 'barrington'],

            // Chicago (long-shot but tracks brand)
            ['q' => 'kitchen remodeling Chicago IL',            'location' => 'Chicago, Illinois, United States',            'city_slug' => 'chicago'],
            ['q' => 'bathroom remodeling Chicago IL',           'location' => 'Chicago, Illinois, United States',            'city_slug' => 'chicago'],
            ['q' => 'general contractor Chicago IL',            'location' => 'Chicago, Illinois, United States',            'city_slug' => 'chicago'],
            // Service-category long-tail (HQ region)
            ['q' => 'basement finishing Arlington Heights IL',  'location' => 'Arlington Heights, Illinois, United States', 'city_slug' => 'arlington-heights'],
            ['q' => 'home additions Arlington Heights IL',      'location' => 'Arlington Heights, Illinois, United States', 'city_slug' => 'arlington-heights'],
            ['q' => 'room addition contractor Arlington Heights IL', 'location' => 'Arlington Heights, Illinois, United States', 'city_slug' => 'arlington-heights'],
        ],

        // Google Maps — uses SerpApi engine=google_maps with `ll` lat/lng.
        // ll format: @<lat>,<lng>,<zoom>z
        'maps_queries' => [
            ['q' => 'kitchen remodeling',         'll' => '@42.0884,-87.9806,12z', 'city_slug' => 'arlington-heights'],
            ['q' => 'bathroom remodeling',        'll' => '@42.0884,-87.9806,12z', 'city_slug' => 'arlington-heights'],
            ['q' => 'general contractor',         'll' => '@42.0884,-87.9806,12z', 'city_slug' => 'arlington-heights'],
            ['q' => 'home remodeling',            'll' => '@42.0884,-87.9806,12z', 'city_slug' => 'arlington-heights'],
            ['q' => 'kitchen remodeler',          'll' => '@42.0884,-87.9806,12z', 'city_slug' => 'arlington-heights'],
            ['q' => 'bathroom remodeler',         'll' => '@42.0884,-87.9806,12z', 'city_slug' => 'arlington-heights'],
            ['q' => 'basement finishing',         'll' => '@42.0884,-87.9806,12z', 'city_slug' => 'arlington-heights'],
            ['q' => 'home additions',             'll' => '@42.0884,-87.9806,12z', 'city_slug' => 'arlington-heights'],
            ['q' => 'general contractor',         'll' => '@42.1103,-88.0342,12z', 'city_slug' => 'palatine'],
            ['q' => 'general contractor',         'll' => '@42.0664,-87.9373,12z', 'city_slug' => 'mount-prospect'],
        ],

        // How many top listings to keep per snapshot.
        'store_top_n' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Console
    |--------------------------------------------------------------------------
    */
    'search_console' => [
        // Site URL as registered in Search Console. Use sc-domain:gs.construction
        // for the Domain property, or https://gs.construction/ for URL-prefix.
        'site_url' => env('GSC_SEARCH_CONSOLE_SITE_URL', 'sc-domain:gs.construction'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backlink quality monitor
    |--------------------------------------------------------------------------
    |
    | Domain patterns considered "high-quality" referring hosts for a quick
    | authority signal in seo:backlinks-monitor. These are regex snippets
    | evaluated case-insensitively against referring hosts.
    */
    'backlinks' => [
        'high_quality_host_patterns' => [
            '\\.gov$',
            '\\.edu$',
            'wikipedia\\.org$',
            'bbb\\.org$',
            'houzz\\.com$',
            'architecturaldigest\\.com$',
            'forbes\\.com$',
            'nytimes\\.com$',
            'chicagotribune\\.com$',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sitemap Generation Controls
    |--------------------------------------------------------------------------
    |
    | Temporary toggles for indexation strategy while resolving
    | "Crawled - currently not indexed" groups.
    |
    | - include_area_service_pages:
    |     /areas-served/{area}/services/{service}
    | - include_zip_pages:
    |     /service-area/{zip}
    |
    | Set to false (via env) to drop a bucket from sitemap without code edits.
    */
    'sitemap_generation' => [
        'include_area_service_pages' => env('SITEMAP_INCLUDE_AREA_SERVICE_PAGES', true),
        // ZIP pages can be toggled off with env when needed, but default is on.
        'include_zip_pages' => env('SITEMAP_INCLUDE_ZIP_PAGES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | PageSpeed Insights URLs (seo:psi-sync)
    |--------------------------------------------------------------------------
    | Pinned URLs to snapshot with PSI. Command also auto-expands this set
    | with top GSC pages (by 28d impressions) up to psi_max_urls.
    |
    | Override at runtime with --urls=... or --max-urls=...
    */
    'psi_max_urls' => env('SEO_PSI_MAX_URLS', 60),

    'psi_urls' => [
        env('APP_URL', 'https://gs.construction') . '/',
        env('APP_URL', 'https://gs.construction') . '/about',
        env('APP_URL', 'https://gs.construction') . '/projects',
        env('APP_URL', 'https://gs.construction') . '/services/kitchen-remodeling',
        env('APP_URL', 'https://gs.construction') . '/services/bathroom-remodeling',
        env('APP_URL', 'https://gs.construction') . '/areas-served/lake-zurich',
        env('APP_URL', 'https://gs.construction') . '/contact',
    ],
];
