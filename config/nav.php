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
        ['label' => 'Projects', 'href' => '/projects', 'bold' => false],
        ['label' => 'Services', 'href' => '/services', 'bold' => false],
        ['label' => 'About', 'href' => '/about', 'bold' => false],
        ['label' => 'Reviews', 'href' => '/testimonials', 'bold' => true],
        ['label' => 'Contact', 'href' => '/contact', 'bold' => false],
    ],
];
