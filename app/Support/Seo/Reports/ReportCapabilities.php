<?php

namespace App\Support\Seo\Reports;

use SsSystems\Platform\Reports\ReportRegistry;

/**
 * Which of the kit's nine report data-source capabilities THIS site binds
 * (see AppServiceProvider::register()), and — for one report key — whether
 * it can actually run and why not when it can't. The one place this mapping
 * lives (per the porting task), so jpeterson-design's identical file can
 * copy the `requires` => reason table verbatim.
 *
 * Reasons are owner-facing sentences with NO vendor or pipeline names — the
 * admin SEO screen's own rule (SeoAdminScreensTest) forbids those outside a
 * Details accordion, and this app feeds `unavailable_reason` straight into
 * the SEO Reports screen.
 */
final class ReportCapabilities
{
    /**
     * @var array<string, string>
     */
    private const REASONS = [
        'query_metrics' => 'Needs search results data this site has not collected yet.',
        'psi_snapshots' => 'Needs page speed measurements this site does not collect yet.',
        'clarity_metrics' => 'Needs visitor behaviour data. Connect it under Connect Services.',
        'area_catalog' => 'Needs the service area pages this site does not have.',
        'site_identity' => 'Needs the business details this site has not configured.',
    ];

    private const DEFAULT_REASON = 'Not available on this site yet.';

    /**
     * @var list<string>
     */
    private const ALL_CAPABILITIES = [
        'query_metrics',
        'psi_snapshots',
        'page_fetcher',
        'site_catalog',
        'site_identity',
        'area_catalog',
        'health_data',
        'clarity_metrics',
        'cache',
    ];

    /**
     * The capability keys this site binds — every one of the nine, here
     * (mirrors the AppServiceProvider::register() binds). Read through
     * config('seo-reports.capabilities') rather than the constant directly
     * so a test can simulate a site missing one capability (jpeterson-design's
     * actual shape) without touching a real container binding.
     *
     * @return list<string>
     */
    public static function provided(): array
    {
        return (array) config('seo-reports.capabilities', self::ALL_CAPABILITIES);
    }

    /**
     * @return array{available: bool, missing: list<string>, reason: ?string}
     */
    public static function availability(string $key): array
    {
        try {
            $requires = ReportRegistry::get($key)['requires'];
        } catch (\InvalidArgumentException) {
            // Not a report this kit's registry knows about (a site-specific
            // registry entry, or a test fixture injected ad hoc) — never
            // block something this mapping cannot reason about.
            return ['available' => true, 'missing' => [], 'reason' => null];
        }

        $missing = array_values(array_diff($requires, self::provided()));

        if ($missing === []) {
            return ['available' => true, 'missing' => [], 'reason' => null];
        }

        return [
            'available' => false,
            'missing' => $missing,
            'reason' => self::REASONS[$missing[0]] ?? self::DEFAULT_REASON,
        ];
    }
}
