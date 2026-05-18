<?php

/**
 * Competitor comparison page configuration.
 *
 * Public, factual, SEO-safe comparison content for "alternative to / vs"
 * intent searches. Do not include defamatory or unverifiable claims.
 * Keep "competitor" entries to neutral facts (publicly visible categories,
 * service area, etc.) and let the "us" column carry the marketing.
 */

return [

    'enabled' => env('COMPETITOR_PAGES_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Universal Comparison Criteria
    |--------------------------------------------------------------------------
    | These are the rows shown in the comparison table on every per-competitor
    | page. The "us" value is filled from this file; the "them" value is
    | overridable per-competitor (defaults to "Varies — verify directly").
    */
    'criteria' => [
        ['key' => 'ownership',          'label' => 'Ownership',                  'us' => 'Family-owned, father-son team (Greg & Patryk)'],
        ['key' => 'experience',         'label' => 'Combined experience',        'us' => '40+ years'],
        ['key' => 'service_area',       'label' => 'Primary service area',       'us' => 'Northwest Chicago suburbs (Arlington Heights, Palatine, Schaumburg, Barrington, etc.)'],
        ['key' => 'project_types',      'label' => 'Project types',              'us' => 'Kitchen, bathroom, and whole-home remodeling'],
        ['key' => 'permits',            'label' => 'Permit handling',            'us' => 'We pull permits and coordinate inspections'],
        ['key' => 'communication',      'label' => 'Project communication',      'us' => 'Direct line to the owners; weekly progress updates'],
        ['key' => 'photo_proof',        'label' => 'Photo proof',                'us' => 'Hundreds of in-progress and completed project photos on-site'],
        ['key' => 'public_reviews',     'label' => 'Public reviews',             'us' => 'Verified reviews on Google, Houzz, Yelp, and Angi'],
        ['key' => 'estimate',           'label' => 'Estimates',                  'us' => 'Free in-home estimate with itemized scope'],
        ['key' => 'licensed_insured',   'label' => 'Licensed & insured',         'us' => 'Yes'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Competitors
    |--------------------------------------------------------------------------
    | slug         : URL slug used at /compare/{slug}
    | name         : Display name (used carefully — do not put in title tag if
    |                their trademark protection is strict; default templates
    |                use "alternative to" framing, which is SEO-safe).
    | website      : Their public website (rendered as external link with
    |                rel=noopener nofollow).
    | location     : Public, neutral location string.
    | focus        : Public, neutral specialty.
    | them         : Optional per-row overrides keyed by criteria.key.
    | also_known_as: Extra brand variants for query tracking.
    */
    'competitors' => [
        [
            'slug' => 'kitchen-village',
            'name' => 'Kitchen Village',
            'website' => 'https://kitchenvillage.com',
            'location' => 'Chicago area',
            'focus' => 'Kitchen and bath showroom',
            'them' => [
                'project_types' => 'Showroom-led kitchen and bath',
            ],
            'also_known_as' => ['kitchenvillage', 'kitchen village chicago'],
        ],
        [
            'slug' => '4ever-remodeling',
            'name' => '4Ever Remodeling',
            'website' => 'https://4everremodeling.com',
            'location' => 'Chicago area',
            'focus' => 'Full-service remodeling',
            'also_known_as' => ['4ever remodeling', '4everremodeling', 'four ever remodeling'],
        ],
        [
            'slug' => 'airoom',
            'name' => 'Airoom',
            'website' => 'https://www.airoom.com',
            'location' => 'Chicago suburbs',
            'focus' => 'Design-build additions and remodels',
            'also_known_as' => ['airoom architects', 'airoom builders'],
        ],
        [
            'slug' => 'normandy-remodeling',
            'name' => 'Normandy Remodeling',
            'website' => 'https://www.normandyremodeling.com',
            'location' => 'Chicago suburbs',
            'focus' => 'Design-build remodeling',
            'also_known_as' => ['normandy builders', 'normandy design'],
        ],
    ],
];
