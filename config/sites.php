<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Multi-site (tenant) configuration
    |--------------------------------------------------------------------------
    | Public sites live in the `sites` table and are resolved from the request
    | host by App\Http\Middleware\ResolveSite. This file holds what is NOT a
    | tenant: the default fallback and the central admin hosts.
    */

    // Site slug used when no host matches: local dev (127.0.0.1, *.test),
    // console commands, queue workers, and unknown hosts.
    'default' => env('SITES_DEFAULT', 'gsc'),

    // NOTE: 'admin_hosts' used to live here, listing ss.systems as the host
    // the cross-site admin was mounted on. It is gone with the ss tenant —
    // and it was already dead config: nothing in app/, routes/ or bootstrap/
    // ever read it. The /admin/{site}/… routes are registered globally, so
    // the admin is reachable on any host the app serves (gs.construction
    // /admin today), which is what made removing the hub a no-op.

    /*
    |--------------------------------------------------------------------------
    | Tenant-exclusive paths
    |--------------------------------------------------------------------------
    | routes/web.php is one global route table, so every host would otherwise
    | serve every route. Paths listed here 404 on any OTHER tenant
    | (App\Http\Middleware\TenantRouteGuard).
    |
    | Match is prefix-based: 'compare' covers /compare and /compare/anything.
    | Anything NOT listed is universal (/, /contact, /sitemap.xml, /admin, …).
    */
    'exclusive_paths' => [
        'gsc' => [
            // GS-specific content clusters
            'compare',
            'costs',
            'permits',
            'trades',
            'insurance-claims',
            'financing',
            'warranty',
            'process',
            'faq',
            'how-to-choose-a-remodeling-contractor',
            'jobs',
            'reviews',
            'projects',
            'timelapses',
            'areas',
            'areas-served',
            'service-area',
            'services',
            'about',
            'lead-line',
            'zip',
            // shared with jpeterson: gsc serves legacy 301s here
            // (/testimonials → /reviews, /portfolio → /projects)
            'testimonials',
            'portfolio',
        ],

        // J. Peterson Design's page set. 'about' and 'services' also appear in
        // the gsc list above: shared claims — each tenant renders its own
        // themed view; tenants claiming neither (ss) get 404.
        'jpeterson' => [
            'portfolio',
            'testimonials',
            'about',
            'services',
            // Shared claim with gsc, like 'about' and 'services': routes/web.php
            // has one /process route rendering view('process'), and each tenant
            // resolves its own themed copy through the view-finder overlay.
            'process',
            // Market pages (/chicago, /atlanta, /south-haven) — slugs pulled
            // from the same file that registers their routes, so the claim can
            // never drift from the route table.
            ...array_column(
                (array) data_get(require __DIR__.'/sites/jpeterson/markets.php', 'list', []),
                'slug',
            ),
        ],
    ],

];
