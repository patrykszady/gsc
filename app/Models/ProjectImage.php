<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class ProjectImage extends Model
{
    protected $fillable = [
        'project_id',
        'filename',
        'original_filename',
        'path',
        'disk',
        'mime_type',
        'size',
        'width',
        'height',
        'alt_text',
        'caption',
        'is_cover',
        'sort_order',
        'thumbnails',
    ];

    protected $casts = [
        'is_cover' => 'boolean',
        'thumbnails' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getThumbnailUrl(string $size = 'medium'): ?string
    {
        $thumbnails = $this->thumbnails ?? [];
        
        if (isset($thumbnails[$size])) {
            return Storage::disk($this->disk)->url($thumbnails[$size]);
        }
        
        return $this->url;
    }

    public function deleteFile(): void
    {
        // Delete main image
        Storage::disk($this->disk)->delete($this->path);
        
        // Delete thumbnails
        foreach ($this->thumbnails ?? [] as $thumbnail) {
            Storage::disk($this->disk)->delete($thumbnail);
        }
    }

    protected static function booted(): void
    {
        static::deleting(function (ProjectImage $image) {
            $image->deleteFile();
        });
    }
}
