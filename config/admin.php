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
];
