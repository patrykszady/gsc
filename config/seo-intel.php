<?php

/*
|--------------------------------------------------------------------------
| DataForSEO intelligence — per-family knobs
|--------------------------------------------------------------------------
| Kit 0.19.2 (2026-11-01): the nine families are SsSystems\Platform\Seo\
| Intel\Sources\* now, each already constructed (with this site's
| DataForSeoApi/IntelStore/SeoSiteProfile/IntelSettings/connection/tenant
| scope) in App\Providers\AppServiceProvider::registerSeoTools() — the
| kit's IntelRunner takes a plain list of built sources, not a class-string
| 'sources' array read through the container, so that key is gone from
| here. These knobs reach each source through SsSystems\Platform\Seo\Intel\
| IntelSettings (App\Providers\AppServiceProvider binds it from this file's
| 'families' array via IntelSettings::withFamily() per family) rather than
| the old $this->config('seo-intel.families.<family>.<key>') read straight
| off Laravel's config() — see IntelSettings's own docblock.
|
| Three keys from the pre-port config are gone, not merely left stale:
|   - 'backlinks.max_findings' was ALREADY a dead key before this port —
|     BacklinksSource has always read 'max_findings_per_type' instead — so
|     it is renamed here (keeping the tuned value) rather than left
|     orphaned a second time under a new runner.
|   - 'ai_optimization.keyword_limit' and 'serp.per_city' governed the
|     seo_keywords-table "researched phrases" augmentation that the kit
|     does not carry over (that table belongs to a different, unported
|     keyword-research module — see AiOptimizationSource's and
|     SerpSource's own class docblocks). SerpSource now falls back to
|     $this->profile->trackedQueries() (GscSeoProfile, i.e.
|     seo.rank_tracker.web_queries — the same list seo:track-rankings
|     tracks) ahead of the service×town anchor fallback, since
|     'serp.queries' below is empty.
|
| One key is new here: 'backlinks.citations' carries over
| config('brand.profiles') (the directory/review profile URLs the
| backlinks citation-check finding used to read straight off that config
| key via a brand.profiles merge that the kit's BacklinksSource dropped —
| see its own docblock) so that finding keeps checking the same profiles.
*/

return [

    'families' => [
        'onpage' => [
            'max_pages' => (int) env('SEO_INTEL_ONPAGE_MAX_PAGES', 600),
            'max_cost' => 0.20,
            'max_issue_pages' => 300,
            'max_findings' => 40,
            'max_wait' => 600,           // seconds a run waits for the crawl before leaving it for the next pickup
            'min_interval_days' => 6,    // post a new crawl only when the last finished one is this old
            'poll_interval' => 30,
            'score_drop_threshold' => 3,
            'crawl_shrink_pct' => 0.10,
        ],
        'backlinks' => [
            'competitors' => 5,
            'max_cost' => 0.40,
            'max_referring_domains' => 300,
            'money_anchor_pct_warn' => 30,
            'max_findings_per_type' => 25,
            'citations' => (array) config('brand.profiles', []),
        ],
        'labs' => [
            'competitor_limit' => 15,
            'gap_competitors' => 3,
            'gap_limit_per_pair' => 200,
            'max_gap_findings' => 15,
            'relevant_pages_limit' => 50,
            'historical_months' => 12,
            'traffic_targets' => 10,
            'etv_swing_pct' => 0.15,
            'page_drop_pct' => 0.30,
            'new_competitor_top_n' => 10,
            'max_cost' => 0.60,
        ],
        'serp' => [
            'queries' => [],   // empty = $this->profile->trackedQueries() (seo.rank_tracker.web_queries), then service×town anchors
            'tracked' => 30,
            'metro_cities' => ['chicago'], // their local pack informs instead of warning
            'depth' => 20,
            'max_findings' => 25,
            'max_cost' => 0.20,
        ],
        'business_data' => [
            'listing_categories' => ['kitchen_remodeler', 'bathroom_remodeler', 'remodeler', 'general_contractor'],
            'listing_radius_km' => 15,
            'listing_limit' => 100,
            'listing_store_top_n' => 25,
            'review_depth' => 100,
            'review_silence_days' => 45,
            'accepted_categories' => ['Kitchen remodeler', 'Bathroom remodeler', 'Remodeler'],
            'max_findings' => 10,
            'max_cost' => 0.30,
            // Google Business Profile Q&A (weekly): our profile plus this many
            // tracked map-pack leaders, and at most this many questions stored
            // per business per run.
            'qa_competitors' => 3,
            'qa_max_questions' => 20,
        ],
        'content_analysis' => [
            'competitors' => 5,
            'mention_limit' => 50,
            'trend_phrases' => ['kitchen remodeling', 'bathroom remodeling'],
            'max_findings' => 20,
            'max_cost' => 0.30,
        ],
        'ai_optimization' => [
            'keywords' => [],  // empty = core phrases + town/service anchors (see AiOptimizationSource)
            'competitors' => 5,
            'max_findings' => 10,
            'max_cost' => 0.40,
        ],
        'domain_analytics' => [
            'competitors' => 8,
            'whois_chunk' => 6,
            'capability_threshold' => 0.6,
            'expiring_days' => 90,
            'max_findings' => 15,
            'max_cost' => 0.50,
        ],
        'trends' => [
            'max_findings' => 15,
            'max_cost' => 0.06,
        ],
    ],

];
