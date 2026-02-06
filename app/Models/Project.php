<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Project extends Model
{
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
        // For image bindings, use our images relationship and support slug or ID
        if ($childType === 'image') {
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

    public function coverImage()
    {
        return $this->hasOne(ProjectImage::class)->where('is_cover', true);
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
}
