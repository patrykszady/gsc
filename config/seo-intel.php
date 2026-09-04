<?php

/*
|--------------------------------------------------------------------------
| DataForSEO intelligence sources
|--------------------------------------------------------------------------
| Each family is an App\Services\Seo\Intel\IntelSource run by `seo:intel`
| on its own schedule (routes/console.php). Every run stores snapshots,
| compares them with the previous run and opens/resolves findings that the
| SEO page, the recommendation engine and the autopilot consume.
| Per-family knobs live under 'families' and are read via $this->config().
*/

return [

    'sources' => [
        \App\Services\Seo\Intel\Sources\OnPageSource::class,
        \App\Services\Seo\Intel\Sources\BacklinksSource::class,
        \App\Services\Seo\Intel\Sources\LabsSource::class,
        \App\Services\Seo\Intel\Sources\SerpSource::class,
        \App\Services\Seo\Intel\Sources\BusinessDataSource::class,
        \App\Services\Seo\Intel\Sources\ContentAnalysisSource::class,
        \App\Services\Seo\Intel\Sources\AiOptimizationSource::class,
        \App\Services\Seo\Intel\Sources\DomainAnalyticsSource::class,
        \App\Services\Seo\Intel\Sources\TrendsSource::class,
    ],

    'families' => [
        // Filled per family; every knob has a code default in its source class.
    ],

];
