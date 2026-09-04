<?php

/*
|--------------------------------------------------------------------------
| DataForSEO intelligence sources
|--------------------------------------------------------------------------
| Each family is an App\Services\Seo\Intel\IntelSource run by `seo:intel`
| on its own schedule (routes/console.php). Every run stores snapshots,
| compares them with the previous run and opens/resolves findings that the
| SEO page, the recommendation engine and the autopilot consume.
| Per-family knobs live under 'families' and are read via $this->config().
*/

return [

    'sources' => [
        \App\Services\Seo\Intel\Sources\OnPageSource::class,
        \App\Services\Seo\Intel\Sources\BacklinksSource::class,
        \App\Services\Seo\Intel\Sources\LabsSource::class,
        \App\Services\Seo\Intel\Sources\SerpSource::class,
        \App\Services\Seo\Intel\Sources\BusinessDataSource::class,
        \App\Services\Seo\Intel\Sources\ContentAnalysisSource::class,
        \App\Services\Seo\Intel\Sources\AiOptimizationSource::class,
        \App\Services\Seo\Intel\Sources\DomainAnalyticsSource::class,
        \App\Services\Seo\Intel\Sources\TrendsSource::class,
    ],

    // Per-family knobs (each source also carries these defaults in code).
    // max_cost is the hard USD ceiling for one run of that family.
    'families' => [
        'onpage' => [
            'max_pages' => (int) env('SEO_INTEL_ONPAGE_MAX_PAGES', 600),
            'max_cost' => 0.20,
            'max_issue_pages' => 300,
            'max_findings' => 40,
            'max_wait' => 1500,
            'poll_interval' => 30,
            'score_drop_threshold' => 3,
            'crawl_shrink_pct' => 0.10,
        ],
        'backlinks' => [
            'competitors' => 5,
            'max_cost' => 0.40,
            'max_referring_domains' => 300,
            'money_anchor_pct_warn' => 30,
            'max_findings' => 25,
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
            'queries' => [],   // empty = derived from seo_keywords + core towns × services
            'tracked' => 30,
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
        ],
        'content_analysis' => [
            'competitors' => 5,
            'mention_limit' => 50,
            'trend_phrases' => ['kitchen remodeling', 'bathroom remodeling'],
            'max_findings' => 20,
            'max_cost' => 0.30,
        ],
        'ai_optimization' => [
            'keywords' => [],  // empty = seo_keywords by opportunity + core phrases
            'keyword_limit' => 100,
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
