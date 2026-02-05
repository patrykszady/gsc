<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        'slug',
        'seo_alt_text',
        'is_cover',
        'sort_order',
        'thumbnails',
        'google_places_media_name',
        'google_places_uploaded_at',
    ];

    protected $casts = [
        'is_cover' => 'boolean',
        'thumbnails' => 'array',
        'google_places_uploaded_at' => 'datetime',
    ];

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Resolve route binding - fall back to ID if slug not found.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        // First try slug, then fall back to ID for backwards compatibility
        return $this->where('slug', $value)->first()
            ?? $this->where('id', $value)->first();
    }
    
    /**
     * Resolve child route binding (when scoped to a parent model like Project).
     */
    public function resolveChildRouteBinding($childType, $value, $field): ?self
    {
        // For scoped bindings, search within this scope (project's images)
        return $this->where('slug', $value)->first()
            ?? $this->where('id', $value)->first();
    }

    /**
     * Generate a unique slug for this image within its project.
     * Creates short, SEO-friendly slugs with location suffix.
     */
    public function generateSlug(): string
    {
        $text = $this->alt_text ?: $this->seo_alt_text ?: pathinfo($this->original_filename, PATHINFO_FILENAME);
        
        // Get project location (e.g., "Palatine, IL" -> "palatine-il")
        $location = '';
        if ($this->project && $this->project->location) {
            $location = Str::slug($this->project->location);
        }
        
        // Clean up common filler words and location from text (to avoid duplication)
        $text = preg_replace('/\b(featuring|with|and|the|in|a|an|for)\b/i', '', $text);
        // Remove location mentions from the text since we'll add it at the end
        if ($location) {
            $locationPattern = preg_quote(str_replace('-', ' ', $location), '/');
            $text = preg_replace("/\b{$locationPattern}\b/i", '', $text);
            // Also remove "IL" separately
            $text = preg_replace('/\b(il|illinois)\b/i', '', $text);
        }
        $text = preg_replace('/\s+/', ' ', trim($text));
        
        // Limit description to ~5 words, then append location
        $descSlug = Str::slug(Str::words($text, 5, ''));
        
        // Fallback if too short
        if (strlen($descSlug) < 5) {
            $descSlug = Str::slug(pathinfo($this->original_filename, PATHINFO_FILENAME));
        }
        
        // Combine description with location
        $baseSlug = $location ? "{$descSlug}-{$location}" : $descSlug;
        
        // Ensure uniqueness within the project
        $slug = $baseSlug;
        $counter = 1;
        
        while (static::where('project_id', $this->project_id)
            ->where('slug', $slug)
            ->where('id', '!=', $this->id ?? 0)
            ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }
        
        return $slug;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }

    public function getUrlAttribute(): ?string
    {
        if (Storage::disk($this->disk)->exists($this->path)) {
            return Storage::disk($this->disk)->url($this->path);
        }
        
        return null;
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
            $webpPath = $thumbnails[$webpKey];
            if (Storage::disk($this->disk)->exists($webpPath)) {
                return Storage::disk($this->disk)->url($webpPath);
            }
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

    /**
     * Check if the image file exists on disk.
     */
    public function fileExists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Get any available URL for this image (WebP preferred, then regular, then main).
     */
    public function getAnyUrl(string $size = 'medium'): ?string
    {
        return $this->getWebpThumbnailUrl($size)
            ?? $this->getThumbnailUrl($size)
            ?? $this->url;
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
