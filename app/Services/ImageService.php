<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectImage;
use Illuminate\Http\UploadedFile;
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
    ];

    protected int $maxWidth = 3200;
    protected int $quality = 92;
    protected int $webpQuality = 85;

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

        // Create database record
        $projectImage = ProjectImage::create([
            'project_id' => $project->id,
            'filename' => $filename,
            'original_filename' => $originalFilename,
            'path' => $path,
            'disk' => 'public',
            'mime_type' => $file->getMimeType(),
            'size' => strlen($encoded),
            'width' => $width,
            'height' => $height,
            'alt_text' => $options['alt_text'] ?? $this->generateAltText($project, $originalFilename),
            'caption' => $options['caption'] ?? null,
            'is_cover' => $options['is_cover'] ?? false,
            'sort_order' => $options['sort_order'] ?? 0,
            'thumbnails' => $thumbnails,
            'webp_path' => $webpPath,
        ]);

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
}
