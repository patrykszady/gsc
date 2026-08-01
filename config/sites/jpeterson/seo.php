<?php

/**
 * J. Peterson Design — SEO package overrides.
 *
 * Injected into the runtime config by SiteConfig::applyRuntime(), so
 * ralphjsmit/laravel-seo picks these up even though it reads config() itself.
 * Only the identity keys are overridden; everything else inherits config/seo.php.
 */
return [
    'site_name' => 'J. Peterson Design',
];
