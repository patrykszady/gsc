<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The one list of services, and the project type each one maps to.
 *
 * Before this, the same list was written out by hand in at least five places —
 * the footer, the projects grid's filter buttons, <x-service-chips>, the
 * service page's slug map, and the deleted internal-links aside. They had all
 * drifted: the footer offered five services and omitted Mudroom, its Projects
 * column omitted Additions and Basements, and nothing linked to /services at
 * all. Adding a service meant finding every copy.
 *
 * Source of truth is config('services-content.services'), which is per-tenant
 * (config/sites/{slug}/services-content.php), so a site that declares no
 * services gets an empty catalog rather than another tenant's list.
 */
class ServiceCatalog
{
    /**
     * Every service the current site publishes, in config order.
     *
     * @return Collection<string, array{slug:string,label:string,shortLabel:string,url:string,projectType:?string,projectsUrl:?string,projectsLabel:?string}>
     */
    public static function all(): Collection
    {
        return collect((array) config('services-content.services', []))
            ->map(function (array $config, string $slug): array {
                $label = $config['title'] ?? str($slug)->replace('-', ' ')->title()->toString();
                $short = $config['shortLabel'] ?? $label;
                $type = $config['projectType'] ?? null;

                return [
                    'slug' => $slug,
                    'label' => $label,
                    'shortLabel' => $short,
                    'url' => url('/services/' . $slug),
                    'projectType' => $type,
                    // The projects index filters by type via query string, so a
                    // type needs no dedicated route to be linkable.
                    'projectsUrl' => $type ? url('/projects?type=' . $type) : null,
                    'projectsLabel' => $type ? $short . ' Projects' : null,
                ];
            });
    }

    /**
     * Services that have a project type, for "Projects" navigation.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public static function withProjects(): Collection
    {
        return self::all()->filter(fn (array $s): bool => filled($s['projectType']));
    }

    /** Map of project type => display label, for filter buttons. */
    public static function projectTypeLabels(): Collection
    {
        return self::withProjects()->mapWithKeys(
            fn (array $s): array => [$s['projectType'] => $s['shortLabel']]
        );
    }

    /**
     * Project types in service order — the order filter buttons appear in.
     *
     * @return Collection<int, string>
     */
    public static function projectTypes(): Collection
    {
        return self::withProjects()->pluck('projectType')->values();
    }

    /** The service that owns a project type, or null. */
    public static function forProjectType(string $type): ?array
    {
        return self::withProjects()->firstWhere('projectType', $type);
    }
}
