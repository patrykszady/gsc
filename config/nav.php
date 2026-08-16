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
        ['label' => 'Careers', 'href' => '/jobs', 'bold' => false, 'moreOnly' => true],
        ['label' => 'Contact', 'href' => '/contact', 'bold' => false],
    ],

    'footer' => [
        /*
         * Area slugs kept OUT of the footer's "Service Areas" column.
         *
         * That column is generated from the admin area list, ordered by how
         * many projects we have in each town, so it needs no maintenance — but
         * a town can rank high and still not belong in a six-item shortlist.
         * Chicago is the case: plenty of completed work, but the footer is
         * meant to read as the suburbs we cover.
         *
         * Excluding here does NOT unpublish the area — /areas-served/chicago
         * and its service spokes stay live and stay in the sitemap. This only
         * affects the footer shortlist.
         */
        'exclude_areas' => ['chicago'],

        /*
         * Towns pinned to the TOP of the footer shortlist regardless of
         * project count. The shortlist is ordered by completed local projects,
         * which means the towns where we're trying to WIN rankings — lots of
         * search impressions, little completed work yet — can never earn a
         * sitewide internal link from it. These are the current
         * striking-distance pages from Search Console (position 8–20 with
         * real demand); a footer link from every page is the cheapest
         * internal-link push they can get. Revisit when rankings move.
         */
        'priority_areas' => ['evanston', 'glenview', 'kenilworth'],
    ],
];
