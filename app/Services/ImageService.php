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
        // Used for hero/large displays (16:9)
        'large' => [2400, 1350],
    ];

    protected int $maxWidth = 3200;
    protected int $quality = 92;

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

        // Generate thumbnails
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
            'alt_text' => $options['alt_text'] ?? pathinfo($originalFilename, PATHINFO_FILENAME),
            'caption' => $options['caption'] ?? null,
            'is_cover' => $options['is_cover'] ?? false,
            'sort_order' => $options['sort_order'] ?? 0,
            'thumbnails' => $thumbnails,
        ]);

        return $projectImage;
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
}
