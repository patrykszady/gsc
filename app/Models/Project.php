<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Hszope\LaravelAigeo\Traits\HasGeoProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RalphJSmit\Laravel\SEO\Support\HasSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;

class Project extends Model
{
    use BelongsToSite;
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
        'yelp_portfolio_url',
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

        // Remember every slug this project has answered on. Photo pages nest
        // under the project slug, so a rename silently moves that project's
        // whole photo set too; without this the old URLs 404 and the rename
        // costs exactly the ranking it was meant to gain.
        static::updating(function (Project $project) {
            if (! $project->isDirty('slug')) {
                return;
            }

            $old = $project->getOriginal('slug');
            if (! $old || $old === $project->slug) {
                return;
            }

            ProjectSlugHistory::updateOrCreate(
                ['slug' => $old],
                ['project_id' => $project->id],
            );

            // A slug being reclaimed by the project that now owns it is no
            // longer historical — drop the redirect so it does not loop.
            ProjectSlugHistory::where('slug', $project->slug)->delete();
        });
    }

    public function slugHistory()
    {
        return $this->hasMany(ProjectSlugHistory::class);
    }

    /**
     * "palatine-il" — the location as it belongs in a URL.
     *
     * Location data is entered by hand and is not uniform: most rows read
     * "Palatine, IL", one uses a period ("Chicago. IL") and one omits the
     * state entirely ("Arlington Heights"). Split on either separator and
     * default the state, so a typo cannot leak into a permanent URL.
     */
    public function citySlug(): ?string
    {
        $location = trim((string) $this->location);
        if ($location === '') {
            return null;
        }

        $parts = preg_split('/[,.]/', $location, 2);
        $city = Str::slug(trim($parts[0] ?? ''));
        if ($city === '') {
            return null;
        }

        $state = Str::slug(trim($parts[1] ?? '')) ?: 'il';

        return $city.'-'.$state;
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
            $slug = $original.'-'.++$count;
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

    /**
     * The image every public surface should lead with: the admin-chosen cover
     * (is_cover), falling back to the first image by sort order.
     *
     * Exists because the site's cards all rendered images->first() while the
     * admin edit page set is_cover — so featuring an image in admin changed
     * nothing on the website. Uses the loaded images collection, not a query,
     * so eager-loaded pages pay nothing extra.
     */
    public function cover(): ?ProjectImage
    {
        return $this->images->firstWhere('is_cover', true) ?? $this->images->first();
    }

    public function blogPost(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(BlogPost::class);
    }

    /** Designer / architect / engineer / trade partner credits, admin-entered. */
    public function collaborators(): HasMany
    {
        return $this->hasMany(ProjectCollaborator::class)->orderBy('sort_order')->orderBy('id');
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
        $loc = $this->location ?: 'Chicago Suburbs';

        return [
            'brand' => 'GS Construction',
            'name' => $this->title,
            'description' => $this->description,
            'url' => url('/projects/'.$this->slug),
            'image' => $this->cover()?->url,
            'sku' => 'project-'.$this->id,
            'price' => 'Contact for quote',
            'currency' => 'USD',
            'in_stock' => true,
            'rating' => 5,
            'review_count' => max(1, $this->testimonials()->count()),
            'reviews' => $this->testimonials->take(3)->map(fn ($t) => [
                'author' => $t->display_name,
                'rating' => $t->star_rating ?? 5,
                'body' => $t->review_description,
                'date' => optional($t->review_date)->toDateString(),
            ])->all(),
            'breadcrumb' => [
                ['name' => 'Home', 'url' => url('/')],
                ['name' => 'Projects', 'url' => url('/projects')],
                ['name' => $type, 'url' => url('/projects?type='.$this->project_type)],
                ['name' => $this->title, 'url' => url('/projects/'.$this->slug)],
            ],
            'faqs' => [
                ['question' => "Where was this {$type} project completed?", 'answer' => "This {$type} project was completed by GS Construction in {$loc}, IL."],
                ['question' => "How long does a {$type} project like this take?", 'answer' => 'GS Construction typically completes a project of this scope in 4–10 weeks depending on permits, materials, and structural changes.'],
                ['question' => 'Is GS Construction licensed and insured?', 'answer' => 'Yes — GS Construction is fully licensed, bonded, and insured for residential remodeling in Illinois.'],
            ],
            'attributes' => array_filter([
                'Project Type' => $type,
                'Location' => $loc,
                'Completed' => optional($this->completed_at)->format('F Y'),
                'Service Area' => 'Chicago Suburbs',
                'Contractor' => 'GS Construction',
            ]),
        ];
    }

    /**
     * Per-record SEO data fed to ralphjsmit/laravel-seo.
     * Uses the cover image if present, otherwise the first project image.
     */
    public function getDynamicSEOData(): SEOData
    {
        $type = self::projectTypes()[$this->project_type] ?? 'Remodel';
        $image = $this->cover()?->url;
        $loc = $this->location ? " in {$this->location}" : '';

        return new SEOData(
            title: "{$this->title} — {$type}{$loc} | GS Construction",
            description: Str::limit(strip_tags((string) $this->description) ?: "Custom {$type} project by GS Construction{$loc}.", 158),
            author: 'GS Construction',
            image: $image ? (str_starts_with($image, 'http') ? $image : url($image)) : null,
            url: url('/projects/'.$this->slug),
            published_time: $this->created_at,
            modified_time: $this->updated_at,
            section: $type,
            tags: array_filter([$type, 'Remodeling', 'Chicago Suburbs', $this->location]),
            type: 'article',
            locale: 'en_US',
        );
    }

    /**
     * Serialization for the /api/admin/v1 management API (called by the
     * ss-systems central admin). Field set matches jpeterson-design's
     * Project::toApiArray() — the two sites' APIs present one contract.
     * gsc-only extras (yelp_portfolio_url, testimonial links, partners) are
     * deliberately not exposed.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'project_type' => $this->project_type,
            'location' => $this->location,
            'completed_at' => optional($this->completed_at)->format('Y-m-d'),
            'is_featured' => (bool) $this->is_featured,
            'is_published' => (bool) $this->is_published,
            'sort_order' => (int) $this->sort_order,
            'cover_url' => $this->cover()?->url,
            'images' => $this->images->map(fn (ProjectImage $image) => $image->toApiArray())->all(),
        ];
    }
}
