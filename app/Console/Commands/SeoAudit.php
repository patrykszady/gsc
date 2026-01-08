<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\Testimonial;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SeoAudit extends Command
{
    protected $signature = 'seo:audit 
                            {--fix : Auto-fix issues where possible}
                            {--detailed : Show detailed output}';

    protected $description = 'Audit SEO elements across the site (alt text, content length, meta data)';

    protected int $issueCount = 0;
    protected int $fixedCount = 0;

    public function handle(): int
    {
        $this->info('🔍 Running SEO Audit...');
        $this->newLine();

        $this->auditProjectImages();
        $this->auditProjects();
        $this->auditTestimonials();
        $this->auditViewFiles();
        $this->auditSitemap();

        $this->newLine();
        
        if ($this->issueCount === 0) {
            $this->info('✅ No SEO issues found!');
            return Command::SUCCESS;
        }

        $this->warn("⚠️  Found {$this->issueCount} SEO issue(s)");
        
        if ($this->option('fix')) {
            $this->info("✅ Auto-fixed {$this->fixedCount} issue(s)");
        } else {
            $this->line('Run with --fix to auto-fix issues where possible');
        }

        return Command::SUCCESS;
    }

    protected function auditProjectImages(): void
    {
        $this->info('📷 Checking project images...');
        
        $imagesWithoutAlt = ProjectImage::whereNull('alt_text')
            ->orWhere('alt_text', '')
            ->get();

        foreach ($imagesWithoutAlt as $image) {
            $this->issueCount++;
            $this->warn("  Missing alt text: Image #{$image->id} ({$image->filename})");
            
            if ($this->option('fix') && $image->project) {
                $altText = $this->generateAltText($image);
                $image->update(['alt_text' => $altText]);
                $this->fixedCount++;
                $this->line("    → Fixed: \"{$altText}\"");
            }
        }

        // Check for duplicate alt text
        $duplicates = ProjectImage::selectRaw('alt_text, COUNT(*) as count')
            ->whereNotNull('alt_text')
            ->where('alt_text', '!=', '')
            ->groupBy('alt_text')
            ->having('count', '>', 3)
            ->get();

        foreach ($duplicates as $dup) {
            $this->issueCount++;
            $this->warn("  Overused alt text ({$dup->count}x): \"{$dup->alt_text}\"");
        }

        $total = ProjectImage::count();
        $withAlt = ProjectImage::whereNotNull('alt_text')->where('alt_text', '!=', '')->count();
        $this->line("  {$withAlt}/{$total} images have alt text");
    }

    protected function auditProjects(): void
    {
        $this->newLine();
        $this->info('📁 Checking projects...');

        // Projects without descriptions
        $noDescription = Project::whereNull('description')
            ->orWhere('description', '')
            ->where('is_published', true)
            ->get();

        foreach ($noDescription as $project) {
            $this->issueCount++;
            $this->warn("  Missing description: {$project->title}");
        }

        // Projects with short descriptions (< 100 chars)
        $shortDescription = Project::where('is_published', true)
            ->whereNotNull('description')
            ->whereRaw('LENGTH(description) < 100')
            ->get();

        foreach ($shortDescription as $project) {
            $this->issueCount++;
            $this->warn("  Short description (" . strlen($project->description) . " chars): {$project->title}");
        }

        // Projects without cover images
        $noCover = Project::where('is_published', true)
            ->whereDoesntHave('images', fn($q) => $q->where('is_cover', true))
            ->get();

        foreach ($noCover as $project) {
            $this->issueCount++;
            $this->warn("  No cover image: {$project->title}");
            
            if ($this->option('fix')) {
                $firstImage = $project->images()->first();
                if ($firstImage) {
                    $firstImage->update(['is_cover' => true]);
                    $this->fixedCount++;
                    $this->line("    → Fixed: Set first image as cover");
                }
            }
        }

        $total = Project::where('is_published', true)->count();
        $this->line("  {$total} published projects audited");
    }

    protected function auditTestimonials(): void
    {
        $this->newLine();
        $this->info('⭐ Checking testimonials...');

        // Short testimonials
        $short = Testimonial::whereRaw('LENGTH(review_description) < 50')->get();
        
        foreach ($short as $testimonial) {
            $this->issueCount++;
            $this->warn("  Very short review (" . strlen($testimonial->review_description) . " chars): {$testimonial->reviewer_name}");
        }

        // Missing project type
        $noType = Testimonial::whereNull('project_type')
            ->orWhere('project_type', '')
            ->get();

        foreach ($noType as $testimonial) {
            $this->issueCount++;
            $this->warn("  Missing project type: {$testimonial->reviewer_name}");
        }

        $total = Testimonial::count();
        $this->line("  {$total} testimonials audited");
    }

    protected function auditViewFiles(): void
    {
        $this->newLine();
        $this->info('📄 Checking view files...');

        $viewPath = resource_path('views');
        $files = File::allFiles($viewPath);
        
        $imgWithoutAlt = 0;
        $imgWithoutLazy = 0;

        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $content = File::get($file->getPathname());
            $relativePath = str_replace($viewPath . '/', '', $file->getPathname());

            // Check for <img> tags without alt
            if (preg_match_all('/<img[^>]+>/i', $content, $matches)) {
                foreach ($matches[0] as $imgTag) {
                    if (!preg_match('/\balt\s*=/i', $imgTag)) {
                        $imgWithoutAlt++;
                        if ($this->option('detailed')) {
                            $this->warn("  Missing alt in {$relativePath}");
                        }
                    }
                    
                    // Check lazy loading (skip if it has loading="eager" for above-fold)
                    if (!preg_match('/\bloading\s*=/i', $imgTag) && 
                        !preg_match('/\bx-bind:loading/i', $imgTag)) {
                        $imgWithoutLazy++;
                    }
                }
            }
        }

        if ($imgWithoutAlt > 0) {
            $this->issueCount++;
            $this->warn("  {$imgWithoutAlt} <img> tag(s) without alt attribute");
        }

        if ($imgWithoutLazy > 0) {
            $this->warn("  {$imgWithoutLazy} <img> tag(s) without loading attribute (consider lazy loading)");
        }

        $this->line("  " . count($files) . " view files scanned");
    }

    protected function auditSitemap(): void
    {
        $this->newLine();
        $this->info('🗺️  Checking sitemap...');

        $sitemapPath = public_path('sitemap.xml');
        
        if (!File::exists($sitemapPath)) {
            $this->issueCount++;
            $this->warn("  Sitemap not found at public/sitemap.xml");
            $this->line("  Run: php artisan sitemap:generate");
            return;
        }

        $content = File::get($sitemapPath);
        $urlCount = substr_count($content, '<url>');
        $lastModified = File::lastModified($sitemapPath);
        $daysOld = (time() - $lastModified) / 86400;

        $this->line("  {$urlCount} URLs in sitemap");
        $this->line("  Last updated: " . date('Y-m-d H:i', $lastModified) . " (" . round($daysOld, 1) . " days ago)");

        if ($daysOld > 7) {
            $this->issueCount++;
            $this->warn("  Sitemap is more than 7 days old - consider regenerating");
        }
    }

    protected function generateAltText(ProjectImage $image): string
    {
        $project = $image->project;
        
        if (!$project) {
            return 'Remodeling project image';
        }

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
}
