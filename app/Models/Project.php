<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Hszope\LaravelAigeo\Traits\HasGeoProfile;
use RalphJSmit\Laravel\SEO\Support\HasSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;

class Project extends Model
{
    use HasGeoProfile;
    use HasSEO;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'project_type',
        'location',
        'completed_at',
        'is_featured',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'completed_at' => 'date',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            if (empty($project->slug)) {
                $project->slug = static::generateUniqueSlug($project->title);
            }
        });
    }

    /**
     * Generate a unique slug, appending a numeric suffix if needed.
     */
    public static function generateUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $slug = Str::slug($title);
        $original = $slug;
        $count = 1;

        $query = static::where('slug', $slug);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        while ($query->exists()) {
            $slug = $original . '-' . ++$count;
            $query = static::where('slug', $slug);
            if ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            }
        }

        return $slug;
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
    
    /**
     * Resolve child route binding for nested routes (e.g., /projects/{project}/photos/{image}).
     */
    public function resolveChildRouteBinding($childType, $value, $field)
    {
        // For image bindings, use our images relationship and support slug or ID.
        // Laravel may pass either the route parameter name ("image") or the model class.
        if ($childType === 'image' || $childType === ProjectImage::class) {
            return $this->images()
                ->where('slug', $value)
                ->orWhere('id', $value)
                ->first();
        }
        
        return parent::resolveChildRouteBinding($childType, $value, $field);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProjectImage::class)->orderBy('sort_order');
    }

    public function timelapses(): HasMany
    {
        return $this->hasMany(ProjectTimelapse::class)->orderBy('sort_order');
    }

    public function beforeAfters(): HasMany
    {
        return $this->hasMany(ProjectBeforeAfter::class)->orderBy('sort_order');
    }

    public function coverImage()
    {
        return $this->hasOne(ProjectImage::class)->where('is_cover', true);
    }

    public function testimonials(): BelongsToMany
    {
        return $this->belongsToMany(Testimonial::class)->withTimestamps();
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('project_type', $type);
    }

    public static function projectTypes(): array
    {
        return [
            'kitchen' => 'Kitchen Remodel',
            'bathroom' => 'Bathroom Remodel',
            'basement' => 'Basement Finish',
            'addition' => 'Home Addition',
            'home-remodel' => 'Home Remodel',
            'mudroom' => 'Mudroom / Laundry',
            'exterior' => 'Exterior/Siding',
        ];
    }

    /**
     * GEO profile for the laravel-aigeo package.
     */
    public function geoProfile(): array
    {
        $type = self::projectTypes()[$this->project_type] ?? ucwords(str_replace('-', ' ', $this->project_type ?? 'Remodel'));
        $loc  = $this->location ?: 'Chicago Suburbs';

        return [
            'brand'        => 'GS Construction',
            'name'         => $this->title,
            'description'  => $this->description,
            'url'          => url('/projects/' . $this->slug),
            'image'        => optional($this->images()->first())->url,
            'sku'          => 'project-' . $this->id,
            'price'        => 'Contact for quote',
            'currency'     => 'USD',
            'in_stock'     => true,
            'rating'       => 5,
            'review_count' => max(1, $this->testimonials()->count()),
            'reviews'      => $this->testimonials->take(3)->map(fn ($t) => [
                'author' => $t->display_name,
                'rating' => $t->star_rating ?? 5,
                'body'   => $t->review_description,
                'date'   => optional($t->review_date)->toDateString(),
            ])->all(),
            'breadcrumb'   => [
                ['name' => 'Home', 'url' => url('/')],
                ['name' => 'Projects', 'url' => url('/projects')],
                ['name' => $type, 'url' => url('/projects?type=' . $this->project_type)],
                ['name' => $this->title, 'url' => url('/projects/' . $this->slug)],
            ],
            'faqs'         => [
                ['question' => "Where was this {$type} project completed?", 'answer' => "This {$type} project was completed by GS Construction in {$loc}, IL."],
                ['question' => "How long does a {$type} project like this take?", 'answer' => "GS Construction typically completes a project of this scope in 4–10 weeks depending on permits, materials, and structural changes."],
                ['question' => "Is GS Construction licensed and insured?", 'answer' => "Yes — GS Construction is fully licensed, bonded, and insured for residential remodeling in Illinois."],
            ],
            'attributes'   => array_filter([
                'Project Type'    => $type,
                'Location'        => $loc,
                'Completed'       => optional($this->completed_at)->format('F Y'),
                'Service Area'    => 'Chicago Suburbs',
                'Contractor'      => 'GS Construction',
            ]),
        ];
    }

    /**
     * Per-record SEO data fed to ralphjsmit/laravel-seo.
     * Uses the cover image if present, otherwise the first project image.
     */
    public function getDynamicSEOData(): SEOData
    {
        $type  = self::projectTypes()[$this->project_type] ?? 'Remodel';
        $image = optional($this->coverImage()->first() ?: $this->images()->first())->url;
        $loc   = $this->location ? " in {$this->location}" : '';

        return new SEOData(
            title:        "{$this->title} — {$type}{$loc} | GS Construction",
            description:  Str::limit(strip_tags((string) $this->description) ?: "Custom {$type} project by GS Construction{$loc}.", 158),
            author:       'GS Construction',
            image:        $image ? (str_starts_with($image, 'http') ? $image : url($image)) : null,
            url:          url('/projects/' . $this->slug),
            published_time: $this->created_at,
            modified_time:  $this->updated_at,
            section:      $type,
            tags:         array_filter([$type, 'Remodeling', 'Chicago Suburbs', $this->location]),
            type:         'article',
            locale:       'en_US',
        );
    }

    /**
     * Lazy-create (and reuse) a short URL homeowners can use to leave a Google review.
     * Uses ShortLink dedup so calling this repeatedly for the same destination URL
     * always returns the same /s/{code}.
     */
    public function getReviewRequestUrl(): ?string
    {
        $destination = config('services.google.review_request_url')
            ?: config('socials.google.url');

        if (! $destination) {
            return null;
        }

        $link = \App\Models\ShortLink::shorten($destination);

        return url('/s/' . $link->code);
    }
}
