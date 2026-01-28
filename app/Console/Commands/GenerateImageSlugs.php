<?php

namespace App\Console\Commands;

use App\Models\ProjectImage;
use Illuminate\Console\Command;

class GenerateImageSlugs extends Command
{
    protected $signature = 'images:generate-slugs {--force : Regenerate all slugs, even existing ones}';
    
    protected $description = 'Generate SEO-friendly slugs for project images';

    public function handle(): int
    {
        $query = ProjectImage::query();
        
        if (!$this->option('force')) {
            $query->whereNull('slug');
        }
        
        $count = $query->count();
        
        if ($count === 0) {
            $this->info('No images need slug generation.');
            return self::SUCCESS;
        }
        
        $this->info("Generating slugs for {$count} images...");
        
        $bar = $this->output->createProgressBar($count);
        $bar->start();
        
        $query->each(function (ProjectImage $image) use ($bar) {
            $image->slug = $image->generateSlug();
            $image->save();
            $bar->advance();
        });
        
        $bar->finish();
        $this->newLine();
        $this->info('Done!');
        
        return self::SUCCESS;
    }
}
