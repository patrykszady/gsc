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
     * Return the unique postal/ZIP codes served in this area, derived from
     * project locations matched against public/project-zipcodes.csv.
     * Cached for 24h. Used for LocalBusiness `serviceArea` schema.
     *
     * @return array<int, string>
     */
    public function postalCodes(): array
    {
        return cache()->remember("area:{$this->id}:zipcodes", 86400, function (): array {
            $csv = public_path('project-zipcodes.csv');
            if (! is_file($csv)) {
                return [];
            }

            // Build project_id => zip map from the CSV (id column = project id).
            $handle = fopen($csv, 'r');
            if ($handle === false) {
                return [];
            }
            $header = fgetcsv($handle);
            if (! $header) {
                fclose($handle);
                return [];
            }
            $cols = array_map(fn ($c) => strtolower(trim((string) $c)), $header);
            $idIdx = array_search('id', $cols, true);
            $zipIdx = array_search('zip_code', $cols, true);
            if ($idIdx === false || $zipIdx === false) {
                fclose($handle);
                return [];
            }
            $projectZips = [];
            while (($row = fgetcsv($handle)) !== false) {
                $pid = (int) ($row[$idIdx] ?? 0);
                $zip = preg_replace('/\D/', '', (string) ($row[$zipIdx] ?? ''));
                if ($pid > 0 && $zip !== '') {
                    $projectZips[$pid] = $zip;
                }
            }
            fclose($handle);

            if (empty($projectZips)) {
                return [];
            }

            // Find project IDs whose location matches this city.
            $projectIds = Project::query()
                ->where(function ($q) {
                    $q->where('location', $this->city)
                      ->orWhere('location', 'LIKE', $this->city . ',%')
                      ->orWhere('location', 'LIKE', $this->city . ' %');
                })
                ->pluck('id')
                ->all();

            $zips = [];
            foreach ($projectIds as $pid) {
                if (isset($projectZips[$pid])) {
                    $zips[$projectZips[$pid]] = true;
                }
            }
            ksort($zips);
            return array_keys($zips);
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
