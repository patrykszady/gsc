<?php

namespace App\Console\Commands;

use App\Models\ProjectImage;
use App\Services\ImageService;
use Illuminate\Console\Command;

/**
 * Backfill the full-size 4:3 crop used for Google Business Profile posts.
 *
 * GBP renders post images in a 4:3 frame, so the 16:9 images we post get black
 * side bars. New uploads generate this crop automatically; this command creates
 * it for images uploaded before that change.
 */
class GbpGeneratePostImages extends Command
{
    protected $signature = 'gbp:generate-post-images
                            {--missing : Only generate for images that do not already have the 4:3 crop}';

    protected $description = 'Generate the full-size 4:3 GBP post crop for existing project images.';

    public function handle(ImageService $imageService): int
    {
        $onlyMissing = (bool) $this->option('missing');

        $images = ProjectImage::query()->whereNotNull('path')->get();
        $this->info("Processing {$images->count()} images...");

        $bar = $this->output->createProgressBar($images->count());
        $bar->start();

        $generated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($images as $image) {
            try {
                $before = $image->thumbnails['gbp'] ?? null;
                $path = $imageService->generateGbpImageFor($image, $onlyMissing);

                if ($path === null) {
                    $errors++;
                } elseif ($onlyMissing && $path === $before) {
                    $skipped++;
                } else {
                    $generated++;
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Image {$image->id}: {$e->getMessage()}");
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Generated: {$generated}");
        $this->info("Skipped (already present): {$skipped}");
        if ($errors > 0) {
            $this->warn("Errors / missing originals: {$errors}");
        }

        return self::SUCCESS;
    }
}
