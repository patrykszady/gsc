<?php

/**
 * J. Peterson Design — navigation.
 *
 * Overlays config/nav.php. Do NOT add '__replace' here: 'links' is a numeric
 * list, and SiteConfig::merge() already replaces lists wholesale rather than
 * merging them by index. So this set entirely supersedes gs.construction's
 * eleven links — which is required, because /projects, /reviews and /jobs are
 * gsc-exclusive and TenantRouteGuard 404s them on this tenant.
 *
 * Every href must be a path this site claims in config/sites.php
 * 'exclusive_paths', or a universal path like /contact. `php artisan
 * sites:check` fails the build if one of them would 404 on its own site.
 *
 * The theme owns how these render; this file owns which links exist.
 */
return [
    'links' => [
        ['label' => 'Portfolio', 'href' => '/portfolio'],
        ['label' => 'Services', 'href' => '/services'],
        ['label' => 'Process', 'href' => '/process'],
        ['label' => 'About', 'href' => '/about'],
        ['label' => 'Testimonials', 'href' => '/testimonials'],
        ['label' => 'Contact', 'href' => '/contact', 'cta' => true],
    ],
];
