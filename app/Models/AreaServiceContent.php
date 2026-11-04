<?php

namespace App\Models;

use App\Support\ServiceCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * The copy one service page carries in one town: what a kitchen remodel in
 * Western Springs is like, not what Western Springs is like. Generated once
 * per (town, service) by seo:generate-area-service-content.
 */
class AreaServiceContent extends Model
{
    /**
     * The service spokes every area has, by URL slug — areas × services
     * (Patryk, 2026-11-03): one per service on the admin's Services screen
     * (App\Models\Service, in its order) that has a public service page
     * (ServiceCatalog, keyed by the service's project type). A service with
     * no page yet — Exterior/Siding today — is left out until it has one.
     * With no services in the table (a fresh site, most tests) every catalog
     * service with a project type counts. Whether a spoke is indexed is
     * AreaSeoPolicy's call.
     *
     * @return list<string>
     */
    public static function services(): array
    {
        // The catalog is the site's own config (SiteConfig::applyRuntime),
        // empty until the site is bound — never memoise that.
        $catalog = ServiceCatalog::withProjects();
        if ($catalog->isEmpty()) {
            return [];
        }

        return once(function () use ($catalog): array {
            $projectTypes = Schema::hasTable('services') ? Service::ordered()->pluck('slug')->all() : [];

            if ($projectTypes === []) {
                return $catalog->pluck('slug')->values()->all();
            }

            return collect($projectTypes)
                ->map(fn (string $type): ?string => ServiceCatalog::forProjectType($type)['slug'] ?? null)
                ->filter()
                ->unique()
                ->values()
                ->all();
        });
    }

    protected $guarded = [];

    protected $casts = [
        'faq' => 'array',
        'generated_at' => 'datetime',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(AreaServed::class, 'area_served_id');
    }

    /** @return list<array{question: string, answer: string}> */
    public function faqItems(): array
    {
        return AreaServed::normaliseFaq($this->faq);
    }

    public static function label(string $service): string
    {
        return match ($service) {
            'kitchen-remodeling' => 'kitchen remodeling',
            'bathroom-remodeling' => 'bathroom remodeling',
            'home-remodeling' => 'whole-home remodeling',
            'basement-remodeling' => 'basement finishing',
            'home-additions' => 'home additions',
            'mudroom-remodeling' => 'mudroom and laundry room remodeling',
            'exterior-siding' => 'siding and exterior remodeling',
            default => str_replace('-', ' ', $service),
        };
    }
}
