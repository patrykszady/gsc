<?php

use SsSystems\Platform\Reports\ReportRegistry;

/**
 * Registry of scheduled SEO markdown reports. Built from the shared kit's
 * SsSystems\Platform\Reports\ReportRegistry — the ten keys, labels,
 * descriptions and commands are defined ONCE there (see
 * vendor/ss-systems/platform-kit's docs/REPORTS-PORTING.md) so this file
 * never hand-lists a command again. `requires` travels through too, for
 * App\Support\Seo\Reports\ReportCapabilities::availability(). Shared by the
 * admin SeoReports page and the RecommendationEngine's stale-report
 * self-healing.
 */
return [

    'reports' => collect(ReportRegistry::all())->map(fn (array $meta): array => [
        'label' => $meta['label'],
        'command' => $meta['command'],
        'description' => $meta['description'],
        'requires' => $meta['requires'],
    ])->all(),

    // The kit report data-source capabilities this site binds (see
    // AppServiceProvider::register() and App\Support\Seo\Reports\
    // ReportCapabilities) — every one of the nine. A test overrides this to
    // simulate a site missing a capability without touching a real
    // container binding.
    'capabilities' => [
        'query_metrics',
        'psi_snapshots',
        'page_fetcher',
        'site_catalog',
        'site_identity',
        'area_catalog',
        'health_data',
        'clarity_metrics',
        'cache',
    ],

];
