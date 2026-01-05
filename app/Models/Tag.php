<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'type',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tag $tag) {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    public function images(): BelongsToMany
    {
        return $this->belongsToMany(ProjectImage::class)->withTimestamps();
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public static function tagTypes(): array
    {
        return [
            'general' => 'General',
            'style' => 'Style',
            'material' => 'Material',
            'feature' => 'Feature',
            'room' => 'Room',
            'color' => 'Color',
        ];
    }
}
