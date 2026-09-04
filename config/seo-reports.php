<?php

/**
 * Registry of scheduled SEO markdown reports. Keys are file names (without
 * extension) under storage/app/reports/; each entry names the artisan command
 * that regenerates the report. Shared by the admin SeoReports page and the
 * RecommendationEngine's stale-report self-healing.
 */
return [

    'reports' => [
        'competitor-discovery' => [
            'label' => 'Competitor discovery (organic page 1)',
            'command' => 'seo:discover-competitors --max-queries=60 --top=10 --min-appearances=2 --markdown',
            'description' => 'Domains ranking on page one for town × service searches across the areas we serve; cross-check against the geo-grid pack leaders.',
        ],
        'content-decay' => [
            'label' => 'Content decay',
            'command' => 'seo:content-decay --markdown',
            'description' => 'Pages losing clicks or position week over week.',
        ],
        'content-gap' => [
            'label' => 'Content gap (rank 8–20)',
            'command' => 'seo:content-gap --markdown',
            'description' => 'Striking-distance queries clustered into content briefs.',
        ],
        'cwv-template' => [
            'label' => 'CWV by template',
            'command' => 'seo:cwv-template --markdown',
            'description' => 'p75 LCP/INP/CLS per page template with regression alerts.',
        ],
        'gbp-parity' => [
            'label' => 'GBP / local-SEO parity',
            'command' => 'seo:gbp-parity --markdown',
            'description' => 'NAP consistency and Google Business Profile ↔ site service parity.',
        ],
        'internal-link-suggest' => [
            'label' => 'Internal-link suggestions',
            'command' => 'seo:internal-link-suggest --markdown',
            'description' => 'Unlinked plain-text mentions of other pages’ anchors.',
        ],
        'backlinks-monitor' => [
            'label' => 'Backlinks / mentions',
            'command' => 'seo:backlinks-monitor --markdown',
            'description' => 'New and lost referring hosts (via Brave Search).',
        ],
        'schema-audit' => [
            'label' => 'Schema audit',
            'command' => 'seo:schema-audit --markdown',
            'description' => 'JSON-LD coverage and validity sweep.',
        ],
        'area-pages-audit' => [
            'label' => 'Area pages (thin/dup)',
            'command' => 'seo:area-pages-audit --markdown',
            'description' => 'Thin pages and near-duplicate clusters across per-area landing pages.',
        ],
        'health-check' => [
            'label' => 'Local SEO health-check',
            'command' => 'seo:health-check --markdown --min-score=0',
            'description' => 'Composite 0–100 score per URL across title, meta, H1, alt, links, schema, canonical, word count.',
        ],
        'health' => [
            'label' => 'SEO health',
            'command' => 'seo:health --markdown',
            'description' => 'Unified 0–100 SEO pillar dashboard with freshness, rankings, GBP and on-page signals.',
        ],
        'clarity-health' => [
            'label' => 'Clarity health',
            'command' => 'seo:clarity-health --markdown',
            'description' => 'Clarity API/config status, last sync freshness, and latest behavioral metrics snapshot.',
        ],
    ],

];
