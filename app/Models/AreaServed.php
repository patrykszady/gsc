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
        'intro',
        'local_intro',
        'landmarks',
        'permit_notes',
        'ig_location_id',
        'fb_place_id',
    ];

    /**
     * Returns true when this area has at least the short intro filled in.
     * Used by content-depth audit + view to decide whether to render unique blocks.
     */
    public function hasUniqueContent(): bool
    {
        return filled($this->intro) || filled($this->local_intro);
    }

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
     * Published projects whose free-text location resolves to this area's city.
     * Matching mirrors ProjectObserver::normalizeCity() — the leading token before
     * the first comma/period — so "Arlington Heights, IL", "Arlington Heights" and
     * "Chicago. IL" all match correctly without substring false-positives.
     * Cached 6h. Used to surface genuinely local project photos on the area page
     * and to compute an honest per-city sitemap lastmod.
     *
     * @return Collection<int, \App\Models\Project>
     */
    public function localProjects(int $limit = 12): Collection
    {
        $city = trim((string) $this->city);
        if ($city === '') {
            return new Collection;
        }

        $key = "area:{$this->id}:local_projects:{$limit}";

        return cache()->remember($key, 21600, function () use ($city, $limit) {
            $needle = mb_strtolower($city);

            return Project::query()
                ->where('is_published', true)
                ->whereNotNull('location')
                ->where('location', '!=', '')
                ->with('images')
                ->latest('updated_at')
                ->get()
                ->filter(function (Project $project) use ($needle): bool {
                    $parts = preg_split('/[,.]/', (string) $project->location) ?: [];
                    $token = mb_strtolower(trim((string) ($parts[0] ?? '')));

                    return $token === $needle;
                })
                ->take($limit)
                ->values();
        });
    }

    /**
     * One representative image per local project (cover first, else first image),
     * for the area-page project slider. Empty when the city has no local projects.
     *
     * @return Collection<int, \App\Models\ProjectImage>
     */
    public function localProjectImages(int $limit = 6): Collection
    {
        return $this->localProjects()
            ->map(fn (Project $project) => $project->images->firstWhere('is_cover', true) ?? $project->images->first())
            ->filter()
            ->take($limit)
            ->values();
    }

    /**
     * Honest last-modified date for this area's sitemap entry: the most recent of
     * the area row itself and any project completed in this city. Falls back to
     * now() only when there is no local signal at all.
     */
    public function lastmod(): \Illuminate\Support\Carbon
    {
        $localMax = $this->localProjects()->max('updated_at');

        $dates = collect([$this->updated_at, $localMax])->filter();

        return $dates->isNotEmpty()
            ? \Illuminate\Support\Carbon::parse($dates->max())
            : now();
    }

    /**
     * Return the unique postal/ZIP codes served in this area, derived from
     * hive.contractors project data synced into hive_project_zip_counts.
     * Cached for 24h. Used for LocalBusiness `serviceArea` schema.
     *
     * @return array<int, string>
     */
    public function postalCodes(): array
    {
        return cache()->remember("area:{$this->id}:zipcodes", 86400, function (): array {
            return app(\App\Services\HiveProjectsClient::class)->zipsForCity($this->city);
        });
    }

    /**
     * Get areas with their project/testimonial counts, sorted by total count descending.
     * Used on homepage to display "most-served" cities with counts.
     * 
     * @param int $limit Number of areas to return
     * @return Collection<int, AreaServed>
     */
    public static function withProjectCounts(int $limit = 14): Collection
    {
        $areas = static::all();
        
        $areasWithCounts = $areas->map(function (AreaServed $area) {
            $city = trim((string) $area->city);
            if ($city === '') {
                return null;
            }
            
            $needle = mb_strtolower($city);
            
            // Count projects in this city
            $projectCount = Project::query()
                ->where('is_published', true)
                ->whereNotNull('location')
                ->where('location', '!=', '')
                ->get()
                ->filter(function (Project $project) use ($needle): bool {
                    $parts = preg_split('/[,.]/', (string) $project->location) ?: [];
                    $token = mb_strtolower(trim((string) ($parts[0] ?? '')));
                    return $token === $needle;
                })
                ->count();
            
            // Count testimonials in this city
            $testimonialCount = Testimonial::query()
                ->where('is_hidden', false)
                ->get()
                ->filter(function (Testimonial $t) use ($needle): bool {
                    $parts = preg_split('/[,.]/', (string) $t->project_location) ?: [];
                    $token = mb_strtolower(trim((string) ($parts[0] ?? '')));
                    return $token === $needle;
                })
                ->count();
            
            $area->project_count = $projectCount + $testimonialCount;
            return $area;
        })
        ->filter()
        ->sortByDesc('project_count')
        ->take($limit)
        ->values();
        
        return $areasWithCounts;
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
