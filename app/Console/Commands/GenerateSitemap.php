<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Testimonial;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate {--url= : Base URL for the sitemap (defaults to APP_URL)}';

    protected $description = 'Generate the sitemap for the website';

    public function handle(): int
    {
        $baseUrl = rtrim($this->option('url') ?: config('app.url'), '/');
        
        $this->info("Generating sitemap with base URL: {$baseUrl}");

        $sitemap = Sitemap::create();
        $urlCount = 0;
        $imageCount = 0;

        // Get all registered routes
        $routes = collect(Route::getRoutes()->getRoutes());

        // Define patterns to exclude from sitemap
        $excludePatterns = [
            'admin',
            'login',
            'logout',
            'api',
            'robots.txt',
            'log-viewer',
            'reviews',      // redirect to /testimonials
            'contact-us',   // redirect to /contact
            'flux/',        // internal flux assets
            'livewire/',    // internal livewire assets
            'up',           // health check
            'sanctum',      // sanctum routes
        ];
        
        // Exact URIs to exclude (redirects and noindex aliases)
        $excludeExact = [
            'areas',        // alias of /areas-served (noindex)
            'locations',    // alias of /areas-served (noindex)
        ];

        // Static pages from routes (non-parameterized GET routes)
        $staticRoutes = $routes->filter(function ($route) use ($excludePatterns, $excludeExact) {
            $uri = $route->uri();
            
            // Skip routes with parameters
            if (str_contains($uri, '{')) {
                return false;
            }
            
            // Skip exact matches (redirects)
            if (in_array($uri, $excludeExact)) {
                return false;
            }
            
            // Skip excluded patterns
            foreach ($excludePatterns as $pattern) {
                if (str_contains($uri, $pattern)) {
                    return false;
                }
            }
            
            // Only GET routes
            return in_array('GET', $route->methods());
        });

        // Priority mapping for static routes
        $priorities = [
            '/' => 1.0,
            'testimonials' => 0.8,
            'projects' => 0.8,
            'contact' => 0.8,
            'about' => 0.7,
            'services/kitchen-remodeling' => 0.9,
            'services/bathroom-remodeling' => 0.9,
            'services/home-remodeling' => 0.9,
            'services/basement-remodeling' => 0.9,
        ];

        $changeFrequencies = [
            '/' => Url::CHANGE_FREQUENCY_WEEKLY,
            'testimonials' => Url::CHANGE_FREQUENCY_WEEKLY,
            'projects' => Url::CHANGE_FREQUENCY_WEEKLY,
            'contact' => Url::CHANGE_FREQUENCY_MONTHLY,
            'about' => Url::CHANGE_FREQUENCY_MONTHLY,
            'services/kitchen-remodeling' => Url::CHANGE_FREQUENCY_WEEKLY,
            'services/bathroom-remodeling' => Url::CHANGE_FREQUENCY_WEEKLY,
            'services/home-remodeling' => Url::CHANGE_FREQUENCY_WEEKLY,
            'services/basement-remodeling' => Url::CHANGE_FREQUENCY_WEEKLY,
        ];

        foreach ($staticRoutes as $route) {
            $uri = $route->uri() === '/' ? '' : $route->uri();
            $priority = $priorities[$route->uri()] ?? 0.5;
            $changeFreq = $changeFrequencies[$route->uri()] ?? Url::CHANGE_FREQUENCY_MONTHLY;

            $sitemap->add(
                Url::create("{$baseUrl}/{$uri}")
                    ->setLastModificationDate(now())
                    ->setChangeFrequency($changeFreq)
                    ->setPriority($priority)
            );
            $urlCount++;
            $this->line("  Added static: /{$uri}");
        }

        // Add area-served pages
        $this->info("Adding area-served pages to sitemap...");
        $areas = AreaServed::orderBy('city')->get();
        $areaPages = ['', 'contact', 'testimonials', 'projects', 'about', 'services'];
        $areaServicePages = ['kitchens', 'bathrooms', 'home-remodeling'];
        $areaCount = 0;

        foreach ($areas as $area) {
            // Standard area pages
            foreach ($areaPages as $page) {
                $uri = $page ? "areas-served/{$area->slug}/{$page}" : "areas-served/{$area->slug}";
                $priority = $page === '' ? 0.7 : 0.6; // Area home pages slightly higher
                
                $sitemap->add(
                    Url::create("{$baseUrl}/{$uri}")
                        ->setLastModificationDate(now())
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                        ->setPriority($priority)
                );
                $urlCount++;
                $areaCount++;
            }
            
            // Area-specific service pages (high priority for local SEO)
            foreach ($areaServicePages as $servicePage) {
                $uri = "areas-served/{$area->slug}/services/{$servicePage}";
                
                $sitemap->add(
                    Url::create("{$baseUrl}/{$uri}")
                        ->setLastModificationDate(now())
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                        ->setPriority(0.8) // High priority for local service keywords
                );
                $urlCount++;
                $areaCount++;
            }
        }
        $totalPageTypes = count($areaPages) + count($areaServicePages);
        $this->line("  Added {$areaCount} area pages ({$areas->count()} areas × {$totalPageTypes} page types)");

        // Add individual testimonial pages
        $this->info("Adding testimonial pages to sitemap...");
        $testimonials = Testimonial::orderBy('review_date', 'desc')->get();
        $testimonialCount = 0;

        foreach ($testimonials as $testimonial) {
            $sitemap->add(
                Url::create("{$baseUrl}/testimonials/{$testimonial->slug}")
                    ->setLastModificationDate($testimonial->updated_at ?? $testimonial->review_date ?? now())
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                    ->setPriority(0.6)
            );
            $urlCount++;
            $testimonialCount++;
        }
        $this->line("  Added {$testimonialCount} testimonial pages");

        // Add individual project pages
        $this->info("Adding project pages to sitemap...");
        $projects = Project::where('is_published', true)->with('images')->get();
        $projectCount = 0;
        $imageCount = 0;

        foreach ($projects as $project) {
            $sitemap->add(
                Url::create("{$baseUrl}/projects/{$project->slug}")
                    ->setLastModificationDate($project->updated_at ?? now())
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                    ->setPriority(0.7)
            );
            $urlCount++;
            $projectCount++;
            $imageCount += $project->images->count();
        }
        $this->line("  Added {$projectCount} project pages ({$imageCount} total images)");

        // Write to storage first, then copy to public (for Forge zero-downtime deployments)
        $storagePath = storage_path('app/sitemap.xml');
        $publicPath = public_path('sitemap.xml');

        $sitemap->writeToFile($storagePath);

        // Copy to public directory
        if (copy($storagePath, $publicPath)) {
            $this->info('Sitemap generated successfully at public/sitemap.xml');
        } else {
            $this->warn('Sitemap saved to storage/app/sitemap.xml but could not copy to public/');
            $this->warn('You may need to manually symlink or copy it.');
        }

        $this->newLine();
        $this->info("=== Summary ===");
        $this->info("Total URLs: {$urlCount}");
        $this->info("  - Static pages: " . $staticRoutes->count());
        $this->info("  - Area pages: {$areaCount}");
        $this->info("  - Testimonial pages: {$testimonialCount}");
        $this->info("  - Project pages: {$projectCount} ({$imageCount} images)");

        return Command::SUCCESS;
    }
}
