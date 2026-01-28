<?php

namespace App\Console\Commands;

use App\Models\ProjectImage;
use Illuminate\Console\Command;

class BackfillImageAltText extends Command
{
    protected $signature = 'images:backfill-alt 
                            {--dry-run : Show what would be changed without making changes}';

    protected $description = 'Backfill alt_text for project images based on project title';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->warn('DRY RUN - No changes will be made');
        }

        $images = ProjectImage::with('project')
            ->whereNull('alt_text')
            ->orWhere('alt_text', '')
            ->orWhereRaw("alt_text REGEXP '^[A-Za-z0-9_-]+$'") // Matches filename-like patterns
            ->get();

        if ($images->isEmpty()) {
            $this->info('All images already have descriptive alt text!');
            return Command::SUCCESS;
        }

        $this->info("Found {$images->count()} images to update...");
        $updated = 0;

        foreach ($images as $image) {
            $project = $image->project;
            
            if (!$project) {
                $this->warn("  Skipping image #{$image->id} - no project found");
                continue;
            }

            // Generate descriptive alt text
            $altText = $this->generateAltText($project, $image);
            
            $this->line("  Image #{$image->id}: {$image->alt_text} → {$altText}");
            
            if (!$dryRun) {
                $image->update(['alt_text' => $altText]);
            }
            
            $updated++;
        }

        $action = $dryRun ? 'Would update' : 'Updated';
        $this->newLine();
        $this->info("{$action} {$updated} images.");

        return Command::SUCCESS;
    }

    private function generateAltText($project, $image): string
    {
        $projectType = match($project->project_type) {
            'kitchen' => 'kitchen remodel',
            'bathroom' => 'bathroom remodel',
            'basement' => 'basement remodel',
            'home' => 'home remodel',
            default => 'remodeling project',
        };

        $location = $project->location ? " in {$project->location}" : '';
        
        if ($image->is_cover) {
            return "{$project->title} - {$projectType}{$location} by GS Construction";
        }

        // Add position context for non-cover images
        $position = $image->sort_order ?? 1;
        
        return "{$project->title} - {$projectType} photo {$position}{$location}";
    }
}
