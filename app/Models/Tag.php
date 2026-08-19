<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    use BelongsToSite;

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

    /**
     * Management-API shape — see Project::toApiArray(). "images_count"
     * mirrors the legacy admin's TagList (withCount('images')) — reads the
     * eager-counted attribute when the caller loaded it, otherwise falls
     * back to a live count so this never errors.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'images_count' => $this->images_count ?? $this->images()->count(),
        ];
    }
}
