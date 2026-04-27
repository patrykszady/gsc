<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Hszope\LaravelAigeo\Traits\HasGeoProfile;
use RalphJSmit\Laravel\SEO\Support\HasSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;

class Testimonial extends Model
{
    use HasGeoProfile;
    use HasSEO;

    protected $fillable = [
        'reviewer_name',
        'project_location',
        'project_type',
        'review_description',
        'review_date',
        'star_rating',
        'is_hidden',
    ];

    protected $appends = ['slug', 'display_name'];

    protected function casts(): array
    {
        return [
            'review_date' => 'date',
            'is_hidden' => 'boolean',
        ];
    }

    /**
     * Generate slug from project_location and project_type.
     * Format: {location}-{type}-review (e.g., "lake-zurich-il-kitchen-review")
     */
    public function getSlugAttribute(): string
    {
        $location = Str::slug($this->project_location ?? 'chicago');
        $type = Str::slug($this->project_type ?? 'home');
        
        return "{$location}-{$type}-review-{$this->id}";
    }

    /**
     * Resolve route binding by extracting ID from the end of the slug.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        // Extract ID from the end of the slug (e.g., "lake-zurich-il-kitchen-review-42" -> 42)
        if (preg_match('/-(\d+)$/', $value, $matches)) {
            return static::find($matches[1]);
        }

        return null;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function reviewUrls(): HasMany
    {
        return $this->hasMany(ReviewUrl::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    public function scopeVisible($query)
    {
        return $query->where('is_hidden', false);
    }

    /**
     * Public display name: "First L" (last name initial only).
     * Handles "First & First Last" → "First & First L".
     */
    public function getDisplayNameAttribute(): string
    {
        $name = $this->reviewer_name;
        $parts = preg_split('/\s+/', trim($name));

        if (count($parts) < 2) {
            return $name;
        }

        $lastPart = end($parts);

        // Already a single letter (already formatted)
        if (mb_strlen($lastPart) === 1) {
            return $name;
        }

        $initial = mb_strtoupper(mb_substr($lastPart, 0, 1));
        array_pop($parts);

        return implode(' ', $parts) . ' ' . $initial;
    }

    /**
     * GEO profile for the laravel-aigeo package.
     */
    public function geoProfile(): array
    {
        $type = ucfirst($this->project_type ?? 'remodel');
        $loc  = $this->project_location ?: 'Chicago Suburbs';

        return [
            'brand'        => 'GS Construction',
            'name'         => trim(($this->display_name ?? 'Customer') . ' — ' . $type . ' review'),
            'description'  => $this->review_description,
            'url'          => url('/testimonials#review-' . $this->id),
            'rating'       => $this->star_rating ?? 5,
            'review_count' => 1,
            'reviews'      => [[
                'author' => $this->display_name,
                'rating' => $this->star_rating ?? 5,
                'body'   => $this->review_description,
                'date'   => optional($this->review_date)->toDateString(),
            ]],
            'breadcrumb'   => [
                ['name' => 'Home', 'url' => url('/')],
                ['name' => 'Reviews', 'url' => url('/testimonials')],
                ['name' => $this->display_name ?? 'Customer Review', 'url' => url('/testimonials#review-' . $this->id)],
            ],
            'currency'     => 'USD',
            'in_stock'     => true,
            'attributes'   => array_filter([
                'Reviewer'     => $this->display_name,
                'Project Type' => $type,
                'Location'     => $loc,
                'Date'         => optional($this->review_date)->toDateString(),
            ]),
        ];
    }

    /**
     * Per-record SEO data fed to ralphjsmit/laravel-seo.
     */
    public function getDynamicSEOData(): SEOData
    {
        $name = $this->display_name ?? 'Customer';
        $type = ucfirst((string) ($this->project_type ?? 'remodeling'));
        $loc  = $this->project_location ? " in {$this->project_location}" : '';

        return new SEOData(
            title:        "{$name}'s {$type} Review{$loc} | GS Construction",
            description:  Str::limit('"' . strip_tags((string) $this->review_description) . '" — ' . $name . $loc . '.', 158),
            author:       $name,
            url:          url('/testimonials#review-' . $this->id),
            published_time: $this->review_date ?? $this->created_at,
            type:         'article',
            locale:       'en_US',
        );
    }
}
