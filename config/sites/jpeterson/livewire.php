<?php

/**
 * J. Peterson Design — Livewire overrides.
 *
 * Only the navigation progress bar. Livewire ships a blue (#2299dd) that has
 * nothing to do with this studio; the bar is the first thing a visitor sees
 * on every page change, so it uses the logo teal.
 *
 * Overlays the shared config/livewire.php through SiteConfig, which merges by
 * key — every other Livewire setting is inherited untouched, and the other
 * tenants keep the default.
 */
return [
    'navigate' => [
        'show_progress_bar' => true,
        // --color-brand-500, the logo monogram colour.
        'progress_bar_color' => '#4e9da2',
    ],
];
