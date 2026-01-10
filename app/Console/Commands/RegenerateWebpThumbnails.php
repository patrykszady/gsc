<?php

namespace App\Console\Commands;

use App\Models\ProjectImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class RegenerateWebpThumbnails extends Command
{
    protected $signature = 'images:regenerate-webp {--force : Regenerate even if WebP already exists}';

    protected $description = 'Regenerate WebP thumbnails for all project images';

    protected array $thumbnailSizes = [
        'thumb' => [150, 150],
        'small' => [300, 300],
        'medium' => [600, 600],
        'hero' => [1200, 675],
        'large' => [2400, 1350],
    ];

    protected int $webpQuality = 85;

    public function handle(): int
    {
        $force = $this->option('force');
        $images = ProjectImage::all();
        
        $this->info("Processing {$images->count()} images...");
        $bar = $this->output->createProgressBar($images->count());
        
        $regenerated = 0;
        $skipped = 0;
        $errors = 0;
        
        foreach ($images as $image) {
            try {
                $result = $this->processImage($image, $force);
                if ($result === 'regenerated') {
                    $regenerated++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("Error processing image {$image->id}: {$e->getMessage()}");
            }
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine(2);
        
        $this->info("Regenerated: {$regenerated}");
        $this->info("Skipped (already had WebP): {$skipped}");
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }
        
        return self::SUCCESS;
    }

    protected function processImage(ProjectImage $image, bool $force): string
    {
        $thumbnails = $image->thumbnails ?? [];
        $needsUpdate = false;
        
        // Check if we need to generate any WebP versions
        foreach ($this->thumbnailSizes as $size => $dimensions) {
            $webpKey = "{$size}_webp";
            
            // Skip if already exists (unless force)
            if (!$force && isset($thumbnails[$webpKey])) {
                continue;
            }
            
            // Get the original thumbnail path
            if (!isset($thumbnails[$size])) {
                continue;
            }
            
            $originalPath = $thumbnails[$size];
            $originalFullPath = Storage::disk('public')->path($originalPath);
            
            if (!file_exists($originalFullPath)) {
                continue;
            }
            
            // Generate WebP version
            $pathInfo = pathinfo($originalPath);
            $webpPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.webp';
            
            try {
                $img = Image::read($originalFullPath);
                $webpEncoded = $img->toWebp($this->webpQuality)->toString();
                Storage::disk('public')->put($webpPath, $webpEncoded);
                
                $thumbnails[$webpKey] = $webpPath;
                $needsUpdate = true;
            } catch (\Exception $e) {
                // Skip this size
                continue;
            }
        }
        
        if ($needsUpdate) {
            $image->update(['thumbnails' => $thumbnails]);
            return 'regenerated';
        }
        
        return 'skipped';
    }
}
