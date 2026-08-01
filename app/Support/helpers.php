<?php

use App\Support\SiteConfig;

if (! function_exists('site_config')) {
    /**
     * Per-site configuration with fallback to the shared config/ files.
     * Drop-in replacement for config() on any key that varies per tenant.
     */
    function site_config(string $key, mixed $default = null): mixed
    {
        return SiteConfig::get($key, $default);
    }
}
