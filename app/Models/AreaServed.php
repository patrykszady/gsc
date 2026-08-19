<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Services\HiveProjectsClient;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RalphJSmit\Laravel\SEO\Support\HasSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;

class AreaServed extends Model
{
    use BelongsToSite;
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
        'latitude' => 'float',
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
     * Every area, ordered by how many published projects we have in that town
     * (most first), then alphabetically.
     *
     * Drives the "{Service} by City" chip order on the service hub pages, which
     * used to run off a hardcoded list of 14 "priority" slugs kept in the view.
     * That list needed hand-editing whenever the area list changed and had gone
     * stale — it still named a town removed from the area list. Ordering by
     * real project volume puts the towns with the strongest local proof first
     * and needs no maintenance when areas change in admin.
     *
     * Counted in PHP rather than SQL: projects have no area foreign key, they
     * carry a free-text `location`, so matching means normalising the leading
     * town name. At a few hundred projects that is cheaper than a per-area
     * query and avoids MySQL-only string functions.
     *
     * @return Collection<int, AreaServed>
     */
    public static function orderedByLocalProjects(): Collection
    {
        return cache()->remember(Tenancy::cacheKey('areas.ordered_by_projects'), 21600, function (): Collection {
            $counts = Project::query()
                ->where('is_published', true)
                ->whereNotNull('location')
                ->where('location', '!=', '')
                ->pluck('location')
                ->countBy(fn (string $location): string => mb_strtolower(trim(preg_split('/[,.]/', $location)[0] ?? '')));

            return static::query()
                ->orderBy('city')
                ->get()
                // Stable sort (PHP 8+), so towns on an equal count keep the
                // alphabetical order the query already applied.
                ->sortByDesc(fn (self $area): int => $counts[mb_strtolower(trim($area->city))] ?? 0)
                ->values();
        });
    }

    /**
     * Completed jobs from the Hive PM system within `radius` miles of this
     * town's centre, widening to 15 miles if 10 finds nothing.
     *
     * The real proof number for a town — 263 within 10 miles of Prospect
     * Heights, against the 12 photographed projects the gallery can show. The
     * haversine lived only inside App\Livewire\MapSection, so any other block
     * wanting the figure had to re-derive it; the projects page was instead
     * quoting its own page-size cap ("12+").
     *
     * @return array{count:int,radius:int,total:int,zips:int}|null
     */
    public function completedProjectsNearby(float $radius = 10.0): ?array
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        return cache()->remember("area:{$this->id}:hive_nearby:{$radius}", 21600, function () use ($radius) {
            $points = collect(app(HiveProjectsClient::class)->storedZipPoints());
            if ($points->isEmpty()) {
                return null;
            }

            $lat = (float) $this->latitude;
            $lng = (float) $this->longitude;

            $within = function (float $miles) use ($points, $lat, $lng): int {
                return (int) $points->filter(function ($p) use ($lat, $lng, $miles) {
                    $dLat = deg2rad($p['lat'] - $lat);
                    $dLng = deg2rad($p['lng'] - $lng);
                    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($p['lat'])) * sin($dLng / 2) ** 2;

                    return 3959 * 2 * atan2(sqrt($a), sqrt(1 - $a)) <= $miles;
                })->sum('count');
            };

            $used = $radius;
            $count = $within($used);
            if ($count === 0) {
                $used = 15.0;
                $count = $within($used);
            }

            return [
                'count' => $count,
                'radius' => (int) $used,
                'total' => (int) $points->sum('count'),
                'zips' => $points->count(),
            ];
        });
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
                .'* cos(radians(longitude) - radians(?)) '
                .'+ sin(radians(?)) * sin(radians(latitude))))';

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
     * @return Collection<int, Project>
     */
    public function localProjects(int $limit = 12, ?string $projectType = null): Collection
    {
        $city = trim((string) $this->city);
        if ($city === '') {
            return new Collection;
        }

        $key = "area:{$this->id}:local_projects:{$limit}:".($projectType ?: 'all');

        return cache()->remember($key, 21600, function () use ($city, $limit, $projectType) {
            $needle = mb_strtolower($city);

            return Project::query()
                ->where('is_published', true)
                ->whereNotNull('location')
                ->where('location', '!=', '')
                // A bathroom service page showing kitchen photos undercuts the
                // one thing the page is meant to prove.
                ->when($projectType, fn ($q) => $q->where('project_type', $projectType))
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
     * Visible testimonials whose free-text project_location resolves to this
     * area's city, using the same leading-token match as localProjects().
     * Cached 6h. Used for the per-town proof block on area pages.
     *
     * @return Collection<int, Testimonial>
     */
    public function localTestimonials(int $limit = 3): Collection
    {
        $city = trim((string) $this->city);
        if ($city === '') {
            return new Collection;
        }

        $key = "area:{$this->id}:local_testimonials:{$limit}";

        return cache()->remember($key, 21600, function () use ($city, $limit) {
            $needle = mb_strtolower($city);

            return Testimonial::visible()
                ->whereNotNull('project_location')
                ->latest('review_date')
                ->get()
                ->filter(function (Testimonial $testimonial) use ($needle): bool {
                    $parts = preg_split('/[,.]/', (string) $testimonial->project_location) ?: [];
                    $token = mb_strtolower(trim((string) ($parts[0] ?? '')));

                    return $token === $needle;
                })
                ->take($limit)
                ->values();
        });
    }

    /**
     * This town's reviews, topped up from the NEAREST towns until there are
     * enough to fill the section.
     *
     * localTestimonials() alone leaves a three-column grid holding one card —
     * Barrington has exactly one review of its own — and a badge reading
     * "5/5 from 1 verified review from Barrington and surrounding homeowners",
     * where the count contradicts the sentence around it.
     *
     * nearestCities() walks outward by distance, so the fill is always the
     * closest available towns rather than an arbitrary set. Every review is a
     * real review of this business; the UI labels each card with the
     * reviewer's actual town, so nothing is passed off as local.
     *
     * @return Collection<int, Testimonial>
     */
    public function testimonialsWithNeighbours(int $limit = 3): Collection
    {
        $local = $this->localTestimonials($limit);

        if ($local->count() >= $limit) {
            return $local;
        }

        return $local->concat(
            $this->nearbyTestimonials($limit + $local->count())
                ->reject(fn (Testimonial $t) => $local->contains('id', $t->id))
                ->take($limit - $local->count())
        )->values();
    }

    /**
     * Fallback proof for towns with no reviews of their own: testimonials from
     * the geographically nearest served towns, honestly labeled by their real
     * town on the area page (never presented as reviews from this city).
     *
     * @return Collection<int, Testimonial>
     */
    public function nearbyTestimonials(int $limit = 3): Collection
    {
        $key = "area:{$this->id}:nearby_testimonials:{$limit}";

        return cache()->remember($key, 21600, function () use ($limit) {
            $collected = new Collection;

            foreach ($this->nearestCities(40) as $nearby) {
                foreach ($nearby->localTestimonials($limit) as $testimonial) {
                    if ($collected->count() >= $limit) {
                        return $collected->values();
                    }

                    if (! $collected->contains('id', $testimonial->id)) {
                        $collected->push($testimonial);
                    }
                }
            }

            return $collected->values();
        });
    }

    /**
     * Fallback proof for towns with no completed projects of their own:
     * published projects from the geographically nearest served towns (every
     * high-demand town has real projects within ~12 miles). Cards must label
     * each project with its real town — never presented as work in this city.
     *
     * @return Collection<int, Project>
     */
    public function nearbyProjects(int $limit = 6): Collection
    {
        $key = "area:{$this->id}:nearby_projects:{$limit}";

        return cache()->remember($key, 21600, function () use ($limit) {
            $collected = new Collection;

            foreach ($this->nearestCities(40) as $nearby) {
                foreach ($nearby->localProjects($limit) as $project) {
                    if ($collected->count() >= $limit) {
                        return $collected->values();
                    }

                    if (! $collected->contains('id', $project->id)) {
                        $collected->push($project);
                    }
                }
            }

            return $collected->values();
        });
    }

    /**
     * One representative image per local project (cover first, else first image),
     * for the area-page project slider. Empty when the city has no local projects.
     *
     * @return Collection<int, ProjectImage>
     */
    public function localProjectImages(int $limit = 6, ?string $projectType = null): Collection
    {
        return $this->localProjects(12, $projectType)
            // setRelation, not a fresh query: the slide overlay links to the
            // project, and the parent is already loaded here.
            ->map(fn (Project $project) => ($project->images->firstWhere('is_cover', true) ?? $project->images->first())?->setRelation('project', $project))
            ->filter()
            ->take($limit)
            ->values();
    }

    /**
     * Honest last-modified date for this area's sitemap entry: the most recent of
     * the area row itself and any project completed in this city. Falls back to
     * now() only when there is no local signal at all.
     */
    public function lastmod(): Carbon
    {
        $localMax = $this->localProjects()->max('updated_at');

        $dates = collect([$this->updated_at, $localMax])->filter();

        return $dates->isNotEmpty()
            ? Carbon::parse($dates->max())
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
            return app(HiveProjectsClient::class)->zipsForCity($this->city);
        });
    }

    /**
     * Get areas with their project/testimonial counts, sorted by total count descending.
     * Used on homepage to display "most-served" cities with counts.
     *
     * @param  int  $limit  Number of areas to return
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
            title: "{$this->city} Home Remodeling, Kitchen & Bath | GS Construction",
            description: "Family-owned remodeling contractor serving {$this->city}, IL. Kitchen, bathroom & whole-home renovations with free in-home estimates. 40+ years of experience.",
            url: url('/areas-served/'.$this->slug),
            type: 'website',
            locale: 'en_US',
        );
    }

    /**
     * Management-API shape. The shared contract calls this field "name";
     * this app's column is "city" (see AreaController for the inbound
     * mapping). latitude/longitude restored for pixel parity with the
     * legacy AreaForm/AreaList coverage map (Coordinates column, lat/lng
     * fields) — gsc-only, since the map itself is gated on the
     * 'areas-map' ping capability jpeterson doesn't declare.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->city,
            'slug' => $this->slug,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'intro' => $this->intro,
            'local_intro' => $this->local_intro,
            'landmarks' => $this->landmarks,
            'permit_notes' => $this->permit_notes,
        ];
    }
}
