<?php

use App\Models\SeoOverride;
use RalphJSmit\Laravel\SEO\Models\SEO;

return [
    /**
     * The SEO model. You can use this setting to override the model used by the package.
     * Make sure to always extend the old model, so that you'll not lose functionality during upgrades.
     *
     * We use a custom model so that DB-stored admin overrides take precedence
     * over the model's getDynamicSEOData() defaults.
     */
    'model' => SeoOverride::class,

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

    // How the service-page titles name the territory ("Kitchen Remodeling Contractors, Chicago Suburbs").
    'region_label' => env('SEO_REGION_LABEL', 'Chicago Suburbs'),

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
    // The layout declares the icons itself (one candidate per consumer, on
    // stable URLs); the package's own unversioned <link> was a second .ico
    // candidate ahead of them in the head.
    'favicon' => null,

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

    /*
     * These four were nested inside one 'description' array, each using the key
     * 'fallback'. PHP keeps only the last duplicate key, so the description
     * fallback resolved to the string "GS Construction" and the image and
     * author fallbacks were discarded entirely — config('seo.image.fallback')
     * and config('seo.author.fallback') both returned null. The package reads
     * them as four sibling keys (vendor/ralphjsmit/laravel-seo/config/seo.php).
     */
    'description' => [
        /**
         * Fallback description, used where none is set via an associated ->seo
         * model or ->getDynamicSEOData().
         *
         * Carries no review or city count: config is loaded before the database
         * is available, so a number here cannot be kept current and this one had
         * already gone stale at "53+ five-star reviews".
         */
        'fallback' => 'Family-owned kitchen, bathroom, and home remodeling contractor serving the Chicago suburbs, with 40+ years of combined experience.',
    ],

    'image' => [
        /**
         * Fallback OG image. Resolved through secure_url(), so it must be
         * reachable from the public folder.
         */
        'fallback' => 'images/og-default.jpg',
    ],

    'author' => [
        'fallback' => 'GS Construction',
    ],

    'twitter' => [
        /**
         * Username without the '@'.
         */
        '@username' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rank tracker (Search Console data)
    |--------------------------------------------------------------------------
    |
    | Used by `seo:track-rankings` to monitor where GS Construction appears
    | on Google for the queries that matter most, derived from synced
    | gsc_query_metrics. Snapshots are persisted in `seo_rank_snapshots`
    | so we can chart movement over time.
    |
    | Patterns are matched (case-insensitive) against listing titles / domain
    | hosts to identify "us" — covers GBP name variants and the website host.
    */
    'rank_tracker' => [
        // Max USD to spend per seo:track-rankings run on the DataForSEO SERP
        // pass (Standard queue or Live, whichever serp_mode below selects).
        // --budget on the command overrides this.
        'budget' => (float) env('SEO_RANK_TRACKER_BUDGET', 1.0),

        // 'standard' queues checks via task_post/tasks_ready/task_get
        // (~$0.0006/check); 'live' calls serp/google/organic/live/advanced
        // synchronously (~$0.002/check). TrackRankings and SerpSource fall
        // back to 'live' automatically when the standard queue errors.
        'serp_mode' => env('SEO_RANK_TRACKER_SERP_MODE', 'standard'),

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
            'airoom' => ['airoom'],
            'normandy_remodeling' => ['normandy remodeling', 'normandyremodeling'],
            'lifestyle_renovations' => ['lifestyle renovations', 'lifestylerenovations'],
            'jw_construction' => ['jw construction'],
            'siena_construction' => ['siena construction'],
            'sebring_design' => ['sebring design', 'sebringdesignbuild'],
            're_bath' => ['re-bath', 'rebath'],
            'kitchen_master' => ['kitchen master'],
            'remodeling_concepts' => ['remodeling concepts'],
            'chicagoland_remodeling' => ['chicagoland remodeling'],
        ],

        // Google web queries. `location` is the per-suburb vantage the
        // DataForSEO live engine now checks from (2026-09, owner's call —
        // suburb vantage is wanted over one shared Chicago point); the GSC
        // engine still matches on the query text alone, since Search
        // Console carries no per-location signal.
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

        // Google Maps queries (legacy — map-pack visibility now comes from
        // gbp:metrics-sync; kept for reference/history).
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
        env('APP_URL', 'https://gs.construction').'/',
        env('APP_URL', 'https://gs.construction').'/about',
        env('APP_URL', 'https://gs.construction').'/projects',
        env('APP_URL', 'https://gs.construction').'/services/kitchen-remodeling',
        env('APP_URL', 'https://gs.construction').'/services/bathroom-remodeling',
        env('APP_URL', 'https://gs.construction').'/areas-served/lake-zurich',
        env('APP_URL', 'https://gs.construction').'/contact',
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO Autopilot
    |--------------------------------------------------------------------------
    |
    | Self-improving loop (seo:autopilot). When auto_publish_landing_pages is
    | true, Autopilot-generated landing pages go LIVE immediately; when false
    | (default) they are created as drafts for one-click review in
    | /admin/landing-pages before publishing. Leave false until you trust the
    | generated content.
    */
    /*
    | Demand gate for town×service pages (/areas-served/{town}/services/{service}):
    | indexed when the town has local proof (the original rule) OR when Search
    | Console shows at least this many impressions/28d for queries naming that
    | town and service. See AreaSeoPolicy::shouldIndex().
    */
    // 2026-09-14, Patryk's call: every page variant of a town is indexable
    // as long as the town has its own copy. Set either to false to return to
    // the proof/demand gates in AreaSeoPolicy without touching code.
    'area_index_subpages' => (bool) env('SEO_AREA_INDEX_SUBPAGES', true),
    'area_index_service_pages' => (bool) env('SEO_AREA_INDEX_SERVICE_PAGES', true),
    // Town contact pages carry their own booking facts since 2026-09-17 (drive
    // time from the office, the village's permit rules, neighbours on the route)
    // and are indexed; set false to keep them out again. A town's projects/
    // testimonials lists without a project or review of its own stay out: Google
    // crawled them and declined (2026-09-17). Flip to open them again.
    'area_index_contact_pages' => (bool) env('SEO_AREA_INDEX_CONTACT_PAGES', true),
    'area_index_list_spokes_without_proof' => (bool) env('SEO_AREA_INDEX_LIST_SPOKES_WITHOUT_PROOF', false),

    /*
    | When a page FAMILY's template last changed in a way a reader would see.
    | The sitemap dates a town page by the later of its own data and this, so
    | a template change (the About pages losing the company story, the service
    | pages gaining their own copy) is announced honestly, once, and stamping
    | every URL with the deploy date — which Google learns to ignore — is not.
    */
    'area_family_dates' => [
        'contact' => '2026-09-17',
        'about' => '2026-09-17',
        'service' => '2026-09-17',
        'services' => '2026-09-14',
        'projects' => '2026-09-14',
        'testimonials' => '2026-09-14',
        'lead-pipe-replacement' => '2026-09-14',
    ],

    'area_service_demand_impressions' => (int) env('SEO_AREA_SERVICE_DEMAND_IMPRESSIONS', 100),
    // …or this much researched monthly search volume for the town + service (seo_keywords).
    'area_service_demand_volume' => (int) env('SEO_AREA_SERVICE_DEMAND_VOLUME', 50),

    /*
    | Geo-grid map-pack scan (seo:map-pack-grid, weekly, DataForSEO Google Maps
    | at every point): centered on the business (400 N Wheeling Rd, Prospect
    | Heights), 7×7 points out to 15 miles — Lake Bluff to Schaumburg,
    | Barrington to Evanston. ~$0.002 a point (~$0.30/run for 3 keywords).
    | Suburb-level tracking doesn't need the block-level density 11×11 gave
    | (~$0.73/run); the radius — how far out we sample — is unchanged.
    */
    'map_pack' => [
        'center_lat' => (float) env('SEO_MAP_PACK_LAT', 42.102847),
        'center_lng' => (float) env('SEO_MAP_PACK_LNG', -87.9275628),
        'grid_size' => (int) env('SEO_MAP_PACK_GRID', 7),
        'radius_miles' => (float) env('SEO_MAP_PACK_RADIUS', 15),
        'keywords' => ['kitchen remodeling', 'bathroom remodeling', 'remodeling contractor'],
    ],

    /*
    | DataForSEO account (seo:dataforseo-balance-check, daily). The account
    | has been funded once ($51 total, no auto-reload) — the failure mode
    | that matters is silent depletion, not overspend. min_balance is the
    | floor a warning fires below; the scheduled commands' own --budget caps
    | still gate any single run.
    */
    'dataforseo' => [
        'min_balance' => (float) env('SEO_DATAFORSEO_MIN_BALANCE', 10.0),
    ],

    /*
    | GEO measurement (seo:ai-mentions): the towns and services we ask the AI
    | answer engines about, twice a month, via DataForSEO.
    */
    'ai_mentions' => [
        // Empty = the core towns: the six with the most completed projects (AreaServed::coreTowns), the footer's rule.
        'towns' => [],
        'services' => ['kitchen remodeling' => 'kitchen-remodeling', 'bathroom remodeling' => 'bathroom-remodeling'],
    ],

    /*
    | Shared "is this a competitor" data — read by App\Support\Seo\CompetitorFilter,
    | the one place every SEO intel source (LabsSource, SerpSource,
    | SeoDomainOverview, SeoMapPackCompetitors, SeoKeywordResearch,
    | ContentAnalysisSource, SeoDiscoverCompetitors) decides whether a host is
    | a real local remodeling competitor. config/seo.php is shared across
    | every tenant site (config/sites/{slug}/seo.php overrides it per site,
    | same convention as ContentAnalysisSource::brandTerms()), so this list is
    | intentionally NOT tenant-specific — no "gs.construction"-style literal
    | belongs here; every call site already excludes its own domain
    | independently.
    */

    // Directories, marketplaces, review sites, social platforms, big-box
    // retailers, media and B2B SaaS: never a competing remodeling business,
    // however they show up in a SERP, a map-pack "website" field, or a Labs
    // domain list. Union of the old SeoDiscoverCompetitors::$defaultExclusions
    // and ContentAnalysisSource::DIRECTORY_DOMAINS lists (both kept as thin
    // aliases), normalized to full domains, plus two aggregators confirmed in
    // the live labs.competitor snapshots (2026-09-16 read) that were on
    // neither list: thebluebook.com (a contractor trade directory) and
    // procore.com (a B2B construction SaaS platform) — never remodeling
    // competitors. Matched exact-host-or-subdomain (suffix-safe), not by
    // substring, so a host merely containing an exclusion's letters is never
    // caught.
    'competitor_exclusions' => [
        'google.com', 'yelp.com', 'houzz.com', 'angi.com', 'angieslist.com', 'homeadvisor.com', 'thumbtack.com',
        'bbb.org', 'facebook.com', 'instagram.com', 'pinterest.com', 'youtube.com', 'tiktok.com', 'linkedin.com',
        'nextdoor.com', 'mapquest.com', 'yellowpages.com', 'superpages.com', 'manta.com', 'porch.com',
        'buildzoom.com', 'expertise.com', 'wikipedia.org', 'reddit.com', 'tripadvisor.com', 'indeed.com',
        'glassdoor.com', 'craigslist.org', 'amazon.com', 'homedepot.com', 'lowes.com', 'menards.com', 'wayfair.com',
        'ikea.com', 'apple.com', 'bing.com', 'yahoo.com', 'duckduckgo.com', 'birdeye.com', 'trustpilot.com',
        'betterbusinessbureau.org', 'forbes.com', 'bobvila.com', 'thisoldhouse.com', 'architecturaldigest.com',
        'hgtv.com', 'fixr.com', 'modernize.com', 'networx.com', 'chamberofcommerce.com',
        'zillow.com', 'redfin.com', 'realtor.com', 'guildquality.com', 'homeguide.com', 'countryliving.com',
        'servpro.com', 'mrhandyman.com', 'rebath.com', 'westshorehome.com', 'jacuzzibathremodel.com',
        'apartments.com', 'trulia.com', 'yellowbook.com', 'mapcarta.com', 'cylex-usa.com', 'opendoor.com',
        // From ContentAnalysisSource::DIRECTORY_DOMAINS, not already covered above.
        'homestars.com', 'bark.com',
        // Confirmed via live prod data (2026-09-16), on neither prior list.
        'thebluebook.com', 'procore.com',
        // Note: the pre-existing literal 'gs.construction' (tenant-specific)
        // and the bare substring 'angi' (subsumed by 'angi.com' above, and
        // unsafe as a suffix-match entry — it would false-positive on any
        // host merely containing the four letters "angi") are intentionally
        // dropped, not carried over.
    ],

    // A domain whose organic footprint dwarfs any real local remodeler is
    // treated as a giant (a national directory or B2B platform), never a
    // competitor — even one that slipped past the static exclusion list
    // above. Thresholds read live prod data (2026-09-16): the largest real
    // local competitor observed sits at organic_count=1,537 / etv=$7,896
    // (airoom.com); the smallest observed non-local subject sits at
    // organic_count=173,349 / etv=$225,996 (thebluebook.com) — >13x headroom
    // above every real local company, >3.5x below every observed aggregator.
    'competitor_giant_thresholds' => [
        'organic_count' => 20000,
        'organic_etv' => 50000,
    ],

    'autopilot' => [
        // Propose a fresh Google Business Profile description (three variants, review-risk) this often.
        'gbp_description_days' => (int) env('SEO_AUTOPILOT_GBP_DESCRIPTION_DAYS', 90),
        'gbp_description_enabled' => (bool) env('SEO_AUTOPILOT_GBP_DESCRIPTION_ENABLED', true),
        'auto_publish_landing_pages' => env('SEO_AUTOPILOT_AUTO_PUBLISH', false),
        // Covered-town modifier plays ("small bathroom remodel glencoe", "custom
        // basement remodeling mount prospect") need this many impressions/28d
        // before the engine drafts a landing page. 60 kept every real play
        // (30–53 impressions) below the line; the ledger stayed empty for months.
        'modifier_min_impressions' => (int) env('SEO_AUTOPILOT_MODIFIER_MIN_IMPRESSIONS', 30),
        // Keyword research (seo_keywords): a researched phrase needs this monthly
        // search volume to drive a landing page, a title experiment, or a copy refresh.
        'research_min_volume' => (int) env('SEO_AUTOPILOT_RESEARCH_MIN_VOLUME', 30),
        // Copy refreshes per autopilot run (each is one Gemini call and a page rewrite).
        'content_refresh_per_run' => (int) env('SEO_AUTOPILOT_CONTENT_REFRESH_PER_RUN', 2),
    ],
];
