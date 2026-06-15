<?php

/**
 * GBP service catalog — pushed to Google Business Profile via `gbp:services-sync`.
 * Add micro-services that homeowners search for ("walk-in shower install",
 * "quartz countertop install"); each becomes a chip on your GBP listing
 * and improves "near me" matching.
 *
 * Keep names ≤ 80 chars; descriptions ≤ 300.
 */
return [

    /*
     * GBP category IDs (gcid format) used by
     * `google-business-profile:update-profile --categories`.
     *
     * Content-focused default for this site:
     * - Primary: kitchen_remodeler (largest service/page depth)
     * - Additional: bathroom_remodeler, remodeler, general_contractor
     */
    'categories' => [
        'primary' => 'gcid:kitchen_remodeler',
        'additional' => [
            'gcid:bathroom_remodeler',
            'gcid:remodeler',
            'gcid:general_contractor',
        ],
    ],

    /*
     * Optional mapping for equivalent site service slugs.
     * Used by seo:gbp-parity so canonical page slugs can map to
     * semantically equivalent GBP service entries.
     */
    'slug_aliases' => [
        'basement-remodeling' => 'basement-finishing',
        'home-additions' => 'room-addition',
    ],

    /*
     * Service areas pushed to GBP by `google-business-profile:update-profile --service-areas`.
     *
     * Google caps service areas at 20 and does NOT support a radius. Each entry
     * must be a named place (city, county, or region) that resolves to a Google
     * Place ID. A COUNTY (administrative_area_level_2) covers every town inside
     * it while using only ONE of the 20 slots — the closest thing to a "radius".
     *
     * Strategy: a few counties for broad reach + key individual cities (always
     * list the home city explicitly). Leave this array EMPTY to fall back to the
     * 20 cities physically closest to the office (from the AreaServed table).
     *
     * Max 20 entries. Format: "Name, IL, USA".
     */
    'service_areas' => [
        // Core city + priority suburbs (curated market focus)
        'Chicago, IL, USA',
        'Prospect Heights, IL, USA',
        'Arlington Heights, IL, USA',
        'Palatine, IL, USA',
        'Mount Prospect, IL, USA',
        'Kenilworth, IL, USA',
        'Hoffman Estates, IL, USA',

        // Northwest / North suburbs
        'Barrington, IL, USA',
        'Lake Zurich, IL, USA',
        'Buffalo Grove, IL, USA',
        'Northbrook, IL, USA',
        'Glenview, IL, USA',
        'Winnetka, IL, USA',
        'Wilmette, IL, USA',
        'Glencoe, IL, USA',
        'Highland Park, IL, USA',
        'Lake Forest, IL, USA',
        'Inverness, IL, USA',
        'Long Grove, IL, USA',
        'Deerfield, IL, USA',
    ],

    /*
     * Source of truth for Q&A pre-seeding (`gbp:qna-checklist`).
     * Pulled from config/geo-answers.php; this is the manual override list
     * if you want different wording on GBP than on your /geo/answers.json feed.
     */
    'qna_extra' => [
        // ['q' => 'Do you offer financing?', 'a' => '...'],
    ],

    /*
     * Service items pushed to GBP. Organized by category with headers.
     * Each item becomes a searchable chip on your GBP listing and improves local search ranking.
     * Keep names ≤ 80 chars; descriptions ≤ 300.
     */
    /*
     * Each service has:
     *   - name           : pushed to GBP; also used to derive slug for parity audit
     *   - description    : pushed to GBP
     *   - parent         : (optional) slug of the pillar landing page this rolls up
     *                      under; sub-services without their own page are still
     *                      considered "covered" by their parent in seo:gbp-parity.
     *   - pillar         : (optional) true marks the entry as a top-level landing
     *                      page (must have a /services/{slug} route).
     */
    'services' => [
        // ========================================
        // CORE REMODELING SERVICES (broad, high-intent)
        // ========================================
        ['name' => 'Kitchen Remodeling',            'pillar' => true,                      'description' => 'Full kitchen renovation services, from layout planning to final installation.'],
        ['name' => 'Bathroom Remodeling',           'pillar' => true,                      'description' => 'Complete bathroom remodels with modern finishes and durable, code-compliant work.'],
        ['name' => 'Home Remodeling',               'pillar' => true,                      'description' => 'Whole-home and multi-room renovation services for homeowners in Chicagoland.'],
        ['name' => 'Basement Remodeling',           'pillar' => true,                      'description' => 'Basement remodeling and finishing for living space, storage, and home value improvement.'],
        ['name' => 'Home Additions',                'pillar' => true,                      'description' => 'Room and home additions designed to match your home and expand usable space.'],
        ['name' => 'Mudroom Remodeling',            'pillar' => true,                      'description' => 'Mudroom and laundry-area remodeling focused on organization, storage, and durability.'],

        // ========================================
        // GENERAL CONTRACTING & DESIGN-BUILD
        // ========================================
        ['name' => 'General Contractor Services',   'parent' => 'home-remodeling',         'description' => 'Licensed and insured general contracting for residential remodeling projects.'],
        ['name' => 'Design-Build Remodeling',       'parent' => 'home-remodeling',         'description' => 'Integrated design-build process from concept, selections, and planning through construction.'],
        ['name' => 'Interior Renovation Services',  'parent' => 'home-remodeling',         'description' => 'Interior renovation work including layout improvements, finish updates, and carpentry details.'],
        ['name' => 'Project Management',            'parent' => 'home-remodeling',         'description' => 'Remodeling project management with scheduling, trade coordination, and quality control.'],
        ['name' => 'Permit & Inspection Management','parent' => 'home-remodeling',         'description' => 'Permit coordination and inspection management for village and municipal compliance.'],
        ['name' => 'Custom Carpentry & Millwork',   'parent' => 'home-remodeling',         'description' => 'Custom trim, built-ins, and finish carpentry to complete premium remodeling projects.'],
    ],
];
