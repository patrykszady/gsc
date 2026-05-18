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
    'services' => [
        // ========================================
        // KITCHEN REMODELING (5 services)
        // ========================================
        ['name' => 'Kitchen Remodeling',           'description' => 'Full kitchen renovations including cabinets, countertops, flooring, lighting, and appliance install.'],
        ['name' => 'Custom Cabinet Installation',  'description' => 'Custom and semi-custom kitchen and bath cabinetry, hand-finished and installed.'],
        ['name' => 'Quartz Countertop Installation','description' => 'Quartz, granite, and marble countertop fabrication and installation.'],
        ['name' => 'Kitchen Island Build',         'description' => 'Custom kitchen islands with seating, storage, and built-in appliances.'],
        ['name' => 'Backsplash Installation',      'description' => 'Tile, glass, and stone backsplash installation.'],

        // ========================================
        // BATHROOM REMODELING (6 services)
        // ========================================
        ['name' => 'Bathroom Remodeling',          'description' => 'Full bathroom renovations including tile, vanities, plumbing, and fixtures.'],
        ['name' => 'Walk-In Shower Installation',  'description' => 'Frameless glass walk-in showers with custom tile and accessible curbless options.'],
        ['name' => 'Bathtub Replacement',          'description' => 'Soaking, freestanding, and jetted bathtub install and tile surround.'],
        ['name' => 'Bathroom Tile Installation',   'description' => 'Floor and wall tile, porcelain, ceramic, and natural stone.'],
        ['name' => 'Bathroom Vanity Installation', 'description' => 'Single and double vanity install with custom countertops and plumbing.'],
        ['name' => 'Accessible Bathroom Remodel',  'description' => 'Aging-in-place upgrades: grab bars, walk-in tubs, curbless showers.'],

        // ========================================
        // WHOLE HOME REMODELING & ADDITIONS (5 services)
        // ========================================
        ['name' => 'Whole Home Remodel',           'description' => 'Multi-room renovations and full interior gut renovations.'],
        ['name' => 'Open Floor Plan Conversion',   'description' => 'Load-bearing wall removal and open-concept layout conversions.'],
        ['name' => 'Basement Finishing',           'description' => 'Finished basement build-outs with framing, drywall, electrical, and flooring.'],
        ['name' => 'Room Addition',                'description' => 'Sunrooms, master suite additions, second-story additions.'],
        ['name' => 'Home Renovation',              'description' => 'General contractor services for residential remodels across Chicago suburbs.'],

        // ========================================
        // INTERIOR FINISHES (5 services)
        // ========================================
        ['name' => 'Interior Painting',            'description' => 'Walls, ceilings, trim, and cabinet painting.'],
        ['name' => 'Hardwood Flooring Installation','description' => 'Solid hardwood, engineered hardwood, and refinishing.'],
        ['name' => 'Tile Flooring Installation',   'description' => 'Porcelain, ceramic, and natural stone floor tile.'],
        ['name' => 'Drywall Installation & Repair','description' => 'New drywall, patching, and texture matching.'],
        ['name' => 'Trim & Molding Installation',  'description' => 'Crown molding, baseboards, wainscoting, and custom millwork.'],

        // ========================================
        // PROJECT MANAGEMENT & PERMITS (2 services)
        // ========================================
        ['name' => 'Remodeling Project Management','description' => 'End-to-end project management with permits, inspections, and trade coordination.'],
        ['name' => 'Building Permit Service',      'description' => 'Permit application and inspection management for Chicago-area villages.'],
    ],
];
