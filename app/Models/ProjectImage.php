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
            $thumbnailPath = $thumbnails[$size];
            if (Storage::disk($this->disk)->exists($thumbnailPath)) {
                return Storage::disk($this->disk)->url($thumbnailPath);
            }
        }
        
        if (Storage::disk($this->disk)->exists($this->path)) {
            return $this->url;
        }

        return null;
    }

    /**
     * Get WebP URL for the main image (if available).
     */
    public function getWebpUrlAttribute(): ?string
    {
        $webpPath = $this->webp_path ?? null;
        
        if ($webpPath && Storage::disk($this->disk)->exists($webpPath)) {
            return Storage::disk($this->disk)->url($webpPath);
        }
        
        return null;
    }

    /**
     * Get WebP thumbnail URL for a given size (if available).
     */
    public function getWebpThumbnailUrl(string $size = 'medium'): ?string
    {
        $thumbnails = $this->thumbnails ?? [];
        $webpKey = "{$size}_webp";
        
        if (isset($thumbnails[$webpKey])) {
            return Storage::disk($this->disk)->url($thumbnails[$webpKey]);
        }
        
        return null;
    }

    /**
     * Get SEO-friendly alt text for the image.
     * Falls back to a descriptive format if custom alt text looks like a filename.
     */
    public function getSeoAltTextAttribute(): string
    {
        // Check if alt_text looks like a filename (contains underscores or file extension patterns)
        $hasDescriptiveAlt = $this->alt_text 
            && !preg_match('/^[_A-Za-z0-9]+$/', $this->alt_text)
            && !preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $this->alt_text);

        if ($hasDescriptiveAlt) {
            return $this->alt_text;
        }

        // Generate descriptive alt text from project info
        $project = $this->project;
        if (!$project) {
            return 'Home remodeling project photo by GS Construction';
        }

        $projectType = ucfirst(str_replace('-', ' ', $project->project_type ?? 'remodeling'));
        $location = $project->location ? " in {$project->location}" : '';
        
        if ($this->is_cover) {
            return "{$projectType} project{$location} - featured image by GS Construction";
        }

        return "{$projectType} project{$location} by GS Construction";
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
