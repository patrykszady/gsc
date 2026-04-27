<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RalphJSmit\Laravel\SEO\Support\HasSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;

class AreaServed extends Model
{
    use HasSEO;
    protected $table = 'areas_served';

    protected $fillable = [
        'city',
        'slug',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'latitude'  => 'float',
        'longitude' => 'float',
    ];

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the URL for this area's home page.
     */
    public function getUrlAttribute(): string
    {
        return route('areas.show', $this->slug);
    }

    /**
     * Get URL for a specific page within this area.
     */
    public function pageUrl(string $page): string
    {
        return route('areas.page', ['area' => $this->slug, 'page' => $page]);
    }

    /**
     * Get URL for a specific service page within this area.
     * e.g., /areas-served/arlington-heights/services/kitchen-remodeling
     */
    public function serviceUrl(string $service): string
    {
        return route('areas.service', ['area' => $this->slug, 'service' => $service]);
    }

    /**
     * Return the N geographically nearest other AreaServed rows using Haversine.
     * Cached per-area for 24h. Used for "Nearby cities we also serve" SEO blocks.
     *
     * @return Collection<int, AreaServed>
     */
    public function nearestCities(int $limit = 6): Collection
    {
        if ($this->latitude === null || $this->longitude === null) {
            return new Collection;
        }

        $key = "area:{$this->id}:nearest:{$limit}";

        return cache()->remember($key, 86400, function () use ($limit) {
            // Haversine in SQL: 3959 = Earth radius in miles.
            $haversine = '(3959 * acos(cos(radians(?)) * cos(radians(latitude)) '
                . '* cos(radians(longitude) - radians(?)) '
                . '+ sin(radians(?)) * sin(radians(latitude))))';

            return static::query()
                ->select('*')
                ->selectRaw("{$haversine} AS distance_miles", [$this->latitude, $this->longitude, $this->latitude])
                ->where('id', '!=', $this->id)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->orderBy('distance_miles')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Per-record SEO data fed to ralphjsmit/laravel-seo.
     */
    public function getDynamicSEOData(): SEOData
    {
        return new SEOData(
            title:        "Kitchen, Bathroom & Home Remodeling in {$this->city}, IL | GS Construction",
            description:  "Trusted family-owned remodeling contractor serving {$this->city}, IL. Free in-home estimates for kitchen, bathroom, and whole-home renovations. 40+ years experience.",
            url:          url('/areas-served/' . $this->slug),
            type:         'website',
            locale:       'en_US',
        );
    }
}
