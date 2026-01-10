<?php

namespace App\Console\Commands;

use App\Models\ProjectImage;
use App\Services\ImageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RegenerateThumbnails extends Command
{
    protected $signature = 'images:regenerate-thumbnails 
                            {--size= : Specific size to regenerate (thumb, small, medium, hero, large)}
                            {--missing : Only generate missing thumbnails}';

    protected $description = 'Regenerate thumbnails for all project images';

    public function handle(ImageService $imageService): int
    {
        $specificSize = $this->option('size');
        $onlyMissing = $this->option('missing');

        $images = ProjectImage::all();
        $this->info("Processing {$images->count()} images...");

        $bar = $this->output->createProgressBar($images->count());
        $bar->start();

        $generated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($images as $image) {
            try {
                // Get the original image path
                $originalPath = $image->path;
                
                if (!Storage::disk('public')->exists($originalPath)) {
                    $this->newLine();
                    $this->warn("Original image not found: {$originalPath}");
                    $errors++;
                    $bar->advance();
                    continue;
                }

                $result = $imageService->regenerateThumbnails($image, $specificSize, $onlyMissing);
                
                if ($result['generated'] > 0) {
                    $generated += $result['generated'];
                }
                if ($result['skipped'] > 0) {
                    $skipped += $result['skipped'];
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Error processing image {$image->id}: {$e->getMessage()}");
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Thumbnails generated: {$generated}");
        $this->info("Thumbnails skipped: {$skipped}");
        
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        return self::SUCCESS;
    }
}
