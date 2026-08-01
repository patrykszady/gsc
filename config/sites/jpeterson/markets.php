<?php

/**
 * J. Peterson Design — studio markets.
 *
 * SINGLE SOURCE for the three metros. The footer, home strip, about and
 * contact pages render their labels from here, the /{slug} market pages
 * render everything else, and routes/web.php builds its route constraint
 * from this same file — so a market added here gets its page, its links
 * and its route in one edit.
 *
 * This is deliberately NOT AreaServed. That model is gs.construction's
 * local-SEO lattice — 70+ suburbs inside one metro, with permit notes and
 * landmarks per town. These are three metros in three states, two of them
 * an office anchored by a named designer. Different shape, different data.
 * If Jenn ever wants per-suburb pages *within* a metro, AreaServed is
 * already site-scoped and can carry that later without touching this.
 *
 * Facts (offices, who leads where) are from the studio's own published
 * material, reused with Jenn's authorisation — see docs/sites/jpeterson.md.
 * Blurbs are working copy for her approval.
 */
return [
    'list' => [
        [
            'slug' => 'chicago',
            'city' => 'Chicago',
            'state' => 'IL',
            'label' => 'Chicago, IL',
            'office' => true,
            'lead' => 'Jennifer Peterson',
            'region' => 'Chicago and the greater Chicago area',
            'blurb' => 'The studio\'s home since 2008. Jennifer leads the Chicago work — full interior design and kitchen & bath, from city residences to projects across the surrounding suburbs.',
            'lat' => 41.8781,
            'lng' => -87.6298,
        ],
        [
            'slug' => 'atlanta',
            'city' => 'Atlanta',
            'state' => 'GA',
            'label' => 'Atlanta, GA',
            'office' => true,
            'lead' => 'Jill Kearns',
            'region' => 'Atlanta and the metro area',
            'blurb' => 'The studio\'s southern office, led by Jill — fifteen years of residential practice serving Atlanta with the same standard the sisters set in Chicago.',
            'lat' => 33.7490,
            'lng' => -84.3880,
        ],
        [
            'slug' => 'south-haven',
            'city' => 'South Haven',
            'state' => 'MI',
            'label' => 'South Haven, MI',
            'office' => false,
            'lead' => 'Jennifer Peterson',
            'region' => 'South Haven and the Southwest Michigan lakeshore',
            'blurb' => 'Lake houses and getaways along the Southwest Michigan shore — designed by the Chicago studio, with Jennifer leading every project.',
            'lat' => 42.4031,
            'lng' => -86.2736,
        ],
    ],
];
