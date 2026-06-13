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
     * Optional mapping for equivalent site service slugs.
     * Used by seo:gbp-parity so canonical page slugs can map to
     * semantically equivalent GBP service entries.
     */
    'slug_aliases' => [
        'basement-remodeling' => 'basement-finishing',
        'home-additions' => 'room-addition',
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
        // KITCHEN REMODELING (5 services)
        // ========================================
        ['name' => 'Kitchen Remodeling',           'pillar' => true,                       'description' => 'Full kitchen renovations including cabinets, countertops, flooring, lighting, and appliance install.'],
        ['name' => 'Custom Cabinet Installation',  'parent' => 'kitchen-remodeling',       'description' => 'Custom and semi-custom kitchen and bath cabinetry, hand-finished and installed.'],
        ['name' => 'Quartz Countertop Installation','parent' => 'kitchen-remodeling',      'description' => 'Quartz, granite, and marble countertop fabrication and installation.'],
        ['name' => 'Kitchen Island Build',         'parent' => 'kitchen-remodeling',       'description' => 'Custom kitchen islands with seating, storage, and built-in appliances.'],
        ['name' => 'Backsplash Installation',      'parent' => 'kitchen-remodeling',       'description' => 'Tile, glass, and stone backsplash installation.'],

        // ========================================
        // BATHROOM REMODELING (6 services)
        // ========================================
        ['name' => 'Bathroom Remodeling',          'pillar' => true,                       'description' => 'Full bathroom renovations including tile, vanities, plumbing, and fixtures.'],
        ['name' => 'Walk-In Shower Installation',  'parent' => 'bathroom-remodeling',      'description' => 'Frameless glass walk-in showers with custom tile and accessible curbless options.'],
        ['name' => 'Bathtub Replacement',          'parent' => 'bathroom-remodeling',      'description' => 'Soaking, freestanding, and jetted bathtub install and tile surround.'],
        ['name' => 'Bathroom Tile Installation',   'parent' => 'bathroom-remodeling',      'description' => 'Floor and wall tile, porcelain, ceramic, and natural stone.'],
        ['name' => 'Bathroom Vanity Installation', 'parent' => 'bathroom-remodeling',      'description' => 'Single and double vanity install with custom countertops and plumbing.'],
        ['name' => 'Accessible Bathroom Remodel',  'parent' => 'bathroom-remodeling',      'description' => 'Aging-in-place upgrades: grab bars, walk-in tubs, curbless showers.'],

        // ========================================
        // WHOLE HOME REMODELING & ADDITIONS (5 services)
        // ========================================
        ['name' => 'Home Remodeling',              'pillar' => true,                       'description' => 'General contractor services for residential remodels across the Chicago suburbs.'],
        ['name' => 'Whole Home Remodel',           'parent' => 'home-remodeling',          'description' => 'Multi-room renovations and full interior gut renovations.'],
        ['name' => 'Open Floor Plan Conversion',   'parent' => 'home-remodeling',          'description' => 'Load-bearing wall removal and open-concept layout conversions.'],
        ['name' => 'Basement Finishing',           'parent' => 'home-remodeling',          'description' => 'Finished basement build-outs with framing, drywall, electrical, and flooring.'],
        ['name' => 'Room Addition',                'parent' => 'home-remodeling',          'description' => 'Sunrooms, master suite additions, second-story additions.'],

        // ========================================
        // INTERIOR FINISHES (5 services)
        // ========================================
        ['name' => 'Interior Painting',            'parent' => 'home-remodeling',          'description' => 'Walls, ceilings, trim, and cabinet painting.'],
        ['name' => 'Hardwood Flooring Installation','parent' => 'home-remodeling',         'description' => 'Solid hardwood, engineered hardwood, and refinishing.'],
        ['name' => 'Tile Flooring Installation',   'parent' => 'home-remodeling',          'description' => 'Porcelain, ceramic, and natural stone floor tile.'],
        ['name' => 'Drywall Installation & Repair','parent' => 'home-remodeling',          'description' => 'New drywall, patching, and texture matching.'],
        ['name' => 'Trim & Molding Installation',  'parent' => 'home-remodeling',          'description' => 'Crown molding, baseboards, wainscoting, and custom millwork.'],

        // ========================================
        // PROJECT MANAGEMENT & PERMITS (2 services)
        // ========================================
        ['name' => 'Remodeling Project Management','parent' => 'home-remodeling',          'description' => 'End-to-end project management with permits, inspections, and trade coordination.'],
        ['name' => 'Building Permit Service',      'parent' => 'home-remodeling',          'description' => 'Permit application and inspection management for Chicago-area villages.'],
    ],
];
