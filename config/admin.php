<?php

/**
 * Admin UI, per tenant.
 *
 * The admin is ONE shared set of views used to administer every site, so
 * anything identifying — the sidebar name, the logo, the accent colour — has
 * to come from here rather than be hardcoded. Otherwise you sit in
 * /admin/jpeterson-design.com/areas looking at GS Construction's name and
 * GS Construction's blue, with the URL bar as the only clue which tenant you
 * are actually editing.
 *
 * `accent` remaps Tailwind's `sky` ramp for this tenant. Every accent utility
 * in the admin views (bg-sky-100, text-sky-600, …) compiles to
 * var(--color-sky-N), so redefining those variables recolours ~100 usages
 * with no view changes at all. null = leave Tailwind's default sky alone.
 *
 * Per-site overrides live in config/sites/{slug}/admin.php.
 */
return [
    'accent' => null,

    'logo' => 'images/logo.svg',
    'logo_dark' => 'images/logo-dark.svg',

    // The standalone "admin is temporarily unavailable" page
    // (errors/admin-proxy-down) — shown when ss-systems can't be reached, so
    // it carries no stylesheet of its own and takes its look from here. The
    // button uses the accent ramp above (Tailwind's sky when null).
    'proxy_down' => [
        'eyebrow' => 'Admin',
        'font' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'heading_font' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'heading_weight' => '600',
        'background' => '#fafafa',
        'heading' => '#18181b',
        'text' => '#52525b',
        'eyebrow_color' => '#a1a1aa',
        'radius' => '0.5rem',
    ],
];
