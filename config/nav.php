<?php

/**
 * Navigation links - single source of truth for desktop and mobile nav.
 * 
 * Flags:
 * - bold: true = bold text styling
 * - moreOnly: true = only shows in "More" dropdown on desktop
 * - afterDropdown: true = shows after the "More" dropdown on desktop
 */
return [
    'links' => [
        ['label' => 'Kitchens', 'href' => '/services/kitchen-remodeling', 'bold' => false],
        ['label' => 'Bathrooms', 'href' => '/services/bathroom-remodeling', 'bold' => false],
        ['label' => 'Basements', 'href' => '/services/basement-remodeling', 'bold' => false, 'moreOnly' => true],
        ['label' => 'Additions', 'href' => '/services/home-additions', 'bold' => false, 'moreOnly' => true],
        ['label' => 'Mudrooms', 'href' => '/services/mudroom-remodeling', 'bold' => false, 'moreOnly' => true],
        ['label' => 'Projects', 'href' => '/projects', 'bold' => false],
        ['label' => 'Services', 'href' => '/services', 'bold' => false],
        ['label' => 'About', 'href' => '/about', 'bold' => false],
        ['label' => 'Reviews', 'href' => '/reviews', 'bold' => true],
        ['label' => 'Blog', 'href' => '/blog', 'bold' => false],
        ['label' => 'Careers', 'href' => '/jobs', 'bold' => false, 'moreOnly' => true],
        ['label' => 'Contact', 'href' => '/contact', 'bold' => false],
    ],

    'footer' => [
        /*
         * The footer's "Service Areas" column is the six towns with the most
         * completed projects — nothing pinned, nothing excluded (owner's rule,
         * 2026-09-04). Both lists stay as escape hatches for a tenant that
         * needs them; on gs.construction they are empty on purpose.
         */
        'exclude_areas' => [],
        'priority_areas' => [],
    ],
];
