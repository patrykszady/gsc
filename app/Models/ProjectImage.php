<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectImage extends Model
{
    protected $fillable = [
        'project_id',
        'filename',
        'original_filename',
        'path',
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
    ];

    protected $casts = [
        'is_cover' => 'boolean',
        'thumbnails' => 'array',
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

    public function imageSocialPosts(): HasMany
    {
        return $this->hasMany(ImageSocialPost::class);
    }

    public function platformUploads(): HasMany
    {
        return $this->hasMany(ImagePlatformUpload::class);
    }

    public function scopeUploadedTo($query, string $platform)
    {
        return $query->whereHas('platformUploads', fn ($q) => $q->where('platform', $platform));
    }

    public function scopeNotUploadedTo($query, string $platform)
    {
        return $query->whereDoesntHave('platformUploads', fn ($q) => $q->where('platform', $platform));
    }

    public function scopeOrderByUploadedTo($query, string $platform, string $direction = 'desc')
    {
        $sub = ImagePlatformUpload::select('uploaded_at')
            ->whereColumn('project_image_id', 'project_images.id')
            ->where('platform', $platform)
            ->limit(1);
        return $query->orderBy($sub, $direction);
    }

    /**
     * Cached lookup of a single platform upload row.
     */
    public function platformUpload(string $platform): ?ImagePlatformUpload
    {
        $key = '__platformUpload_' . $platform;
        if (! array_key_exists($key, $this->attributes)) {
            $this->attributes[$key] = $this->platformUploads
                ? $this->platformUploads->firstWhere('platform', $platform)
                : $this->platformUploads()->where('platform', $platform)->first();
        }
        return $this->attributes[$key];
    }

    // ------------------------------------------------------------------
    // Convenience accessors that read from the image_platform_uploads table.
    // ------------------------------------------------------------------

    public function getGooglePlacesMediaNameAttribute(): ?string
    {
        return $this->platformUpload(ImagePlatformUpload::PLATFORM_GOOGLE_PLACES)?->remote_id;
    }

    public function getGooglePlacesMediaUrlAttribute(): ?string
    {
        return $this->platformUpload(ImagePlatformUpload::PLATFORM_GOOGLE_PLACES)?->remote_url;
    }

    public function getGooglePlacesUploadedAtAttribute()
    {
        return $this->platformUpload(ImagePlatformUpload::PLATFORM_GOOGLE_PLACES)?->uploaded_at;
    }

    public function getYelpPhotoIdAttribute(): ?string
    {
        return $this->platformUpload(ImagePlatformUpload::PLATFORM_YELP_PORTFOLIO)?->remote_id;
    }

    public function getYelpUploadedAtAttribute()
    {
        return $this->platformUpload(ImagePlatformUpload::PLATFORM_YELP_PORTFOLIO)?->uploaded_at;
    }

    public function getYelpBizPhotoIdAttribute(): ?string
    {
        return $this->platformUpload(ImagePlatformUpload::PLATFORM_YELP_BIZ)?->remote_id;
    }

    public function getYelpBizUploadedAtAttribute()
    {
        return $this->platformUpload(ImagePlatformUpload::PLATFORM_YELP_BIZ)?->uploaded_at;
    }

    public function getYelpBizCaptionAttribute(): ?string
    {
        return $this->platformUpload(ImagePlatformUpload::PLATFORM_YELP_BIZ)?->caption;
    }

    /**
     * Latest published Instagram permalink for this image, if any.
     */
    public function getInstagramUrlAttribute(): ?string
    {
        return $this->imageSocialPosts()
            ->where('platform', 'instagram')
            ->where('status', 'published')
            ->whereNotNull('platform_permalink')
            ->latest('published_at')
            ->value('platform_permalink');
    }

    public function getUrlAttribute(): ?string
    {
        if (Storage::disk('public')->exists($this->path)) {
            return Storage::disk('public')->url($this->path);
        }
        
        return null;
    }

    public function getThumbnailUrl(string $size = 'medium'): ?string
    {
        $thumbnails = $this->thumbnails ?? [];
        
        if (isset($thumbnails[$size])) {
            $thumbnailPath = $thumbnails[$size];
            if (Storage::disk('public')->exists($thumbnailPath)) {
                return Storage::disk('public')->url($thumbnailPath);
            }
        }
        
        if (Storage::disk('public')->exists($this->path)) {
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
        
        if ($webpPath && Storage::disk('public')->exists($webpPath)) {
            return Storage::disk('public')->url($webpPath);
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
            if (Storage::disk('public')->exists($webpPath)) {
                return Storage::disk('public')->url($webpPath);
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
        return Storage::disk('public')->exists($this->path);
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
        Storage::disk('public')->delete($this->path);
        
        // Delete thumbnails
        foreach ($this->thumbnails ?? [] as $thumbnail) {
            Storage::disk('public')->delete($thumbnail);
        }
    }

    protected static function booted(): void
    {
        static::deleting(function (ProjectImage $image) {
            $image->deleteFile();
        });
    }
}
