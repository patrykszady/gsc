<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class ImageService
{
    protected array $thumbnailSizes = [
        'thumb' => [150, 150],
        'small' => [300, 300],
        'medium' => [600, 600],
        // Hero image for tablets/small desktops (16:9)
        'hero' => [1200, 675],
        // Used for hero/large displays (16:9)
        'large' => [2400, 1350],
        // Instagram feed square (1:1) — center-cropped to avoid letterboxing
        'instagram' => [1440, 1440],
    ];

    protected int $maxWidth = 3200;
    protected int $quality = 90;
    protected int $webpQuality = 80; // Reduced from 85 for better performance

    public function upload(UploadedFile $file, Project $project, array $options = []): ProjectImage
    {
        $originalFilename = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $extension;
        $basePath = 'projects/' . $project->id;
        $path = $basePath . '/' . $filename;

        // Create optimized image
        $image = Image::read($file);
        
        // Get original dimensions
        $width = $image->width();
        $height = $image->height();

        // Resize if too large (maintain aspect ratio)
        if ($width > $this->maxWidth) {
            $image->scale(width: $this->maxWidth);
            $width = $image->width();
            $height = $image->height();
        }

        // Encode with quality
        $encoded = $this->encodeImage($image, $extension);

        // Store the main image
        Storage::disk('public')->put($path, $encoded);
        
        // Generate WebP version for modern browsers
        $webpPath = $this->generateWebP($file, $basePath, $filename);

        // Generate thumbnails (now also creates WebP versions)
        $thumbnails = $this->generateThumbnails($file, $basePath, $filename);

        // Full-size 4:3 crop for Google Business Profile posts (GBP renders in a
        // 4:3 frame; a 16:9 image gets black side bars). Native resolution, not
        // a downscaled thumbnail.
        if ($gbpPath = $this->generateGbpImage($file, $basePath, $filename)) {
            $thumbnails['gbp'] = $gbpPath;
        }

        // Create database record
        $attributes = [
            'project_id' => $project->id,
            'filename' => $filename,
            'original_filename' => $originalFilename,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => strlen($encoded),
            'width' => $width,
            'height' => $height,
            'alt_text' => $options['alt_text'] ?? $this->generateAltText($project, $originalFilename),
            'caption' => $options['caption'] ?? null,
            'is_cover' => $options['is_cover'] ?? false,
            'sort_order' => $options['sort_order'] ?? 0,
            'thumbnails' => $thumbnails,
        ];

        if (Schema::hasColumn('project_images', 'webp_path')) {
            $attributes['webp_path'] = $webpPath;
        }

        $projectImage = ProjectImage::create($attributes);

        return $projectImage;
    }

    /**
     * Generate WebP version of the main image for better performance.
     */
    protected function generateWebP(UploadedFile $file, string $basePath, string $filename): ?string
    {
        try {
            $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
            $webpFilename = "{$nameWithoutExt}.webp";
            $webpPath = "{$basePath}/{$webpFilename}";
            
            $image = Image::read($file);
            
            if ($image->width() > $this->maxWidth) {
                $image->scale(width: $this->maxWidth);
            }
            
            $encoded = $image->toWebp($this->webpQuality)->toString();
            Storage::disk('public')->put($webpPath, $encoded);
            
            return $webpPath;
        } catch (\Exception $e) {
            // WebP generation failed, continue without it
            return null;
        }
    }

    protected function generateThumbnails(UploadedFile $file, string $basePath, string $filename): array
    {
        $thumbnails = [];
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

        foreach ($this->thumbnailSizes as $size => [$width, $height]) {
            $image = Image::read($file);
            
            // Cover resize (crop to fit)
            $image->cover($width, $height);
            
            $thumbFilename = "{$nameWithoutExt}_{$size}.{$extension}";
            $thumbPath = "{$basePath}/thumbs/{$thumbFilename}";
            
            $encoded = $this->encodeImage($image, $extension);
            Storage::disk('public')->put($thumbPath, $encoded);
            
            $thumbnails[$size] = $thumbPath;
            
            // Also generate WebP version of thumbnail
            try {
                $webpThumbFilename = "{$nameWithoutExt}_{$size}.webp";
                $webpThumbPath = "{$basePath}/thumbs/{$webpThumbFilename}";
                
                $webpImage = Image::read($file);
                $webpImage->cover($width, $height);
                $webpEncoded = $webpImage->toWebp($this->webpQuality)->toString();
                Storage::disk('public')->put($webpThumbPath, $webpEncoded);
                
                $thumbnails["{$size}_webp"] = $webpThumbPath;
            } catch (\Exception $e) {
                // WebP thumbnail generation failed, continue
            }
        }

        return $thumbnails;
    }

    /**
     * Compute the largest 4:3 crop dimensions that fit inside a source of the
     * given size, without upscaling. Landscape source → full height, cropped
     * width; portrait/near-square source → full width, cropped height.
     *
     * @return array{0:int,1:int} [width, height]
     */
    protected function fourThreeDimensions(int $width, int $height): array
    {
        $ratio = 4 / 3;

        if ($width / $height >= $ratio) {
            return [(int) round($height * $ratio), $height];
        }

        return [$width, (int) round($width / $ratio)];
    }

    /**
     * Generate a full-resolution 4:3 crop for Google Business Profile posts.
     * $source may be an UploadedFile, a file path, or raw image bytes (anything
     * Intervention's Image::read accepts). Returns the stored path or null.
     */
    protected function generateGbpImage($source, string $basePath, string $filename): ?string
    {
        try {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

            $img = Image::read($source);

            // Cap at maxWidth like the main image; never upscale.
            if ($img->width() > $this->maxWidth) {
                $img->scale(width: $this->maxWidth);
            }

            [$w, $h] = $this->fourThreeDimensions($img->width(), $img->height());
            $img->cover($w, $h); // crop to 4:3 at native resolution

            $gbpPath = "{$basePath}/{$nameWithoutExt}_gbp.{$extension}";
            Storage::disk('public')->put($gbpPath, $this->encodeImage($img, $extension));

            return $gbpPath;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Backfill the full-size 4:3 GBP crop for an existing image, recording the
     * path under thumbnails['gbp']. Returns the path or null.
     */
    public function generateGbpImageFor(ProjectImage $image, bool $onlyMissing = false): ?string
    {
        $thumbnails = $image->thumbnails ?? [];

        if ($onlyMissing && ! empty($thumbnails['gbp']) && Storage::disk('public')->exists($thumbnails['gbp'])) {
            return $thumbnails['gbp'];
        }

        $originalPath = $image->path;
        if (! $originalPath || ! Storage::disk('public')->exists($originalPath)) {
            return null;
        }

        $path = $this->generateGbpImage(
            Storage::disk('public')->get($originalPath),
            dirname($originalPath),
            basename($originalPath),
        );

        if ($path) {
            $thumbnails['gbp'] = $path;
            $image->update(['thumbnails' => $thumbnails]);
        }

        return $path;
    }

    protected function encodeImage($image, string $extension): string
    {
        return match (strtolower($extension)) {
            'png' => $image->toPng()->toString(),
            'gif' => $image->toGif()->toString(),
            'webp' => $image->toWebp($this->quality)->toString(),
            default => $image->toJpeg($this->quality)->toString(),
        };
    }

    /**
     * Generate SEO-friendly alt text for an image.
     */
    protected function generateAltText(Project $project, string $originalFilename): string
    {
        $type = ucfirst(str_replace('-', ' ', $project->project_type ?? 'home'));
        $location = $project->location;
        
        // Build descriptive alt text
        $parts = ["{$type} remodeling"];
        
        if ($location) {
            $parts[] = "in {$location}";
        }
        
        $parts[] = 'by GS Construction';

        return implode(' ', $parts);
    }

    public function delete(ProjectImage $image): void
    {
        // The model's deleting event handles file cleanup
        $image->delete();
    }

    public function reorder(Project $project, array $imageIds): void
    {
        foreach ($imageIds as $order => $imageId) {
            ProjectImage::where('id', $imageId)
                ->where('project_id', $project->id)
                ->update(['sort_order' => $order]);
        }
    }

    public function setCover(ProjectImage $image): void
    {
        // Remove cover from all project images
        ProjectImage::where('project_id', $image->project_id)
            ->update(['is_cover' => false]);
        
        // Set this one as cover
        $image->update(['is_cover' => true]);
    }

    /**
     * Regenerate thumbnails for an existing image.
     */
    public function regenerateThumbnails(ProjectImage $image, ?string $specificSize = null, bool $onlyMissing = false): array
    {
        $generated = 0;
        $skipped = 0;

        $originalPath = $image->path;
        $basePath = dirname($originalPath);
        $filename = basename($originalPath);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

        // Read the original image once
        $originalFile = Storage::disk('public')->get($originalPath);
        
        $sizesToGenerate = $specificSize 
            ? [$specificSize => $this->thumbnailSizes[$specificSize] ?? null]
            : $this->thumbnailSizes;

        $thumbnails = $image->thumbnails ?? [];

        foreach ($sizesToGenerate as $size => [$width, $height]) {
            if ($width === null) {
                continue;
            }

            $thumbFilename = "{$nameWithoutExt}_{$size}.{$extension}";
            $thumbPath = "{$basePath}/thumbs/{$thumbFilename}";
            $webpThumbFilename = "{$nameWithoutExt}_{$size}.webp";
            $webpThumbPath = "{$basePath}/thumbs/{$webpThumbFilename}";

            // Skip if only generating missing and file exists
            if ($onlyMissing && Storage::disk('public')->exists($thumbPath)) {
                $skipped++;
                continue;
            }

            // Generate JPEG/PNG thumbnail
            $img = Image::read($originalFile);
            $img->cover($width, $height);
            $encoded = $this->encodeImage($img, $extension);
            Storage::disk('public')->put($thumbPath, $encoded);
            $thumbnails[$size] = $thumbPath;
            $generated++;

            // Generate WebP version
            try {
                if (!$onlyMissing || !Storage::disk('public')->exists($webpThumbPath)) {
                    $webpImg = Image::read($originalFile);
                    $webpImg->cover($width, $height);
                    $webpEncoded = $webpImg->toWebp($this->webpQuality)->toString();
                    Storage::disk('public')->put($webpThumbPath, $webpEncoded);
                    $thumbnails["{$size}_webp"] = $webpThumbPath;
                    $generated++;
                }
            } catch (\Exception $e) {
                // WebP generation failed, continue
            }
        }

        // Update the image record with new thumbnail paths
        $image->update(['thumbnails' => $thumbnails]);

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * Build a pair of 1:1 crops (left half + right half) of the original image
     * for use as an Instagram carousel. Each crop is sized 1440×1440.
     *
     * Returns an array of relative storage paths: ['left' => ..., 'right' => ...].
     */
    public function generateInstagramCarouselCrops(ProjectImage $image): array
    {
        $originalPath = $image->path;
        $basePath = dirname($originalPath);
        $filename = basename($originalPath);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

        $originalFile = Storage::disk('public')->get($originalPath);
        $size = 1440;

        $paths = [];
        foreach (['left', 'right'] as $side) {
            $thumbFilename = "{$nameWithoutExt}_instagram_{$side}.{$extension}";
            $thumbPath = "{$basePath}/thumbs/{$thumbFilename}";

            $img = Image::read($originalFile);
            $w = $img->width();
            $h = $img->height();

            // Each half is half-width × full-height of the source, then cover-cropped to a square.
            $halfW = (int) floor($w / 2);
            $cropX = $side === 'left' ? 0 : ($w - $halfW);
            $img->crop($halfW, $h, $cropX, 0);
            $img->cover($size, $size);

            $encoded = $this->encodeImage($img, $extension);
            Storage::disk('public')->put($thumbPath, $encoded);
            $paths[$side] = $thumbPath;
        }

        // Persist on the model so we can reuse without regenerating
        $thumbnails = $image->thumbnails ?? [];
        $thumbnails['instagram_left'] = $paths['left'];
        $thumbnails['instagram_right'] = $paths['right'];
        $image->update(['thumbnails' => $thumbnails]);

        return $paths;
    }
}
