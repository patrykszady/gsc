<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Testimonial extends Model
{
    protected $fillable = [
        'reviewer_name',
        'project_location',
        'project_type',
        'review_description',
        'review_date',
        'review_url',
        'review_image',
    ];

    protected $appends = ['slug'];

    protected function casts(): array
    {
        return [
            'review_date' => 'date',
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
}
