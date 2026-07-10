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
     * Used by seo:gbp-parity to normalize legacy / 301-redirect slugs that may
     * still surface in the sitemap onto the canonical slug that matches a GBP
     * service entry. Keys are the legacy slug; values are the canonical slug.
     */
    'slug_aliases' => [
        'basement-finishing' => 'basement-remodeling',
        'room-addition'      => 'home-additions',
        'room-additions'     => 'home-additions',
        'mudroom'            => 'mudroom-remodeling',
        'mudrooms'           => 'mudroom-remodeling',
        'laundry-room'       => 'mudroom-remodeling',
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
     * Max 20 entries. Format: "Name, IL, USA".
     *
     * Curation (2026-07): weighted toward the North Shore, where Search Console
     * shows the strongest demand (Kenilworth/Winnetka/Wilmette/Glencoe each pull
     * 1.8k–3.5k impressions/28d) yet we were under-listed, plus the most populous
     * NW suburbs near the Prospect Heights office. Every town sits in Cook or Lake
     * County, so the two counties are covered by specific high-value towns rather
     * than spending scarce slots on broad county entries. Dropped low-demand
     * Chicago (urban, no GBP demand), Hoffman Estates, Barrington, Lake Zurich,
     * Inverness, Long Grove; added Evanston, Skokie, Schaumburg, Des Plaines,
     * Deer Park, Lake Bluff.
     */
    'service_areas' => [
        // County-wide umbrella coverage
        'Cook County, IL, USA',
        'Lake County, IL, USA',

        // North Shore (affluent core) — highest GBP demand, listed explicitly
        // for emphasis even though they fall inside the counties above
        'Wilmette, IL, USA',
        'Winnetka, IL, USA',
        'Kenilworth, IL, USA',
        'Glencoe, IL, USA',
        'Highland Park, IL, USA',
        'Northbrook, IL, USA',
        'Glenview, IL, USA',
        'Deerfield, IL, USA',
        'Lake Forest, IL, USA',
        'Lake Bluff, IL, USA',

        // Northwest suburbs (near the Prospect Heights office)
        'Prospect Heights, IL, USA',
        'Arlington Heights, IL, USA',
        'Mount Prospect, IL, USA',
        'Palatine, IL, USA',
        'Lake Zurich, IL, USA',
        'Barrington, IL, USA',
        'Inverness, IL, USA',
        'Long Grove, IL, USA',
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
