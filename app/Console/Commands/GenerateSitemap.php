<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
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
            'reviews',     // redirect route
            'flux/',       // internal flux assets
            'livewire/',   // internal livewire assets
            'up',          // health check
            'sanctum',     // sanctum routes
        ];

        // Static pages from routes (non-parameterized GET routes)
        $staticRoutes = $routes->filter(function ($route) use ($excludePatterns) {
            $uri = $route->uri();
            
            // Skip routes with parameters
            if (str_contains($uri, '{')) {
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
        ];

        $changeFrequencies = [
            '/' => Url::CHANGE_FREQUENCY_WEEKLY,
            'testimonials' => Url::CHANGE_FREQUENCY_WEEKLY,
            'projects' => Url::CHANGE_FREQUENCY_WEEKLY,
            'contact' => Url::CHANGE_FREQUENCY_MONTHLY,
            'about' => Url::CHANGE_FREQUENCY_MONTHLY,
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

        // Add area-specific pages dynamically based on registered routes
        $areas = AreaServed::all();

        // Detect area sub-pages from routes (routes like areas/{area:slug} or areas/{area:slug}/*)
        $areaRoutes = $routes->filter(function ($route) {
            $uri = $route->uri();
            return (str_starts_with($uri, 'areas/{area') || str_starts_with($uri, 'areas/{area:slug}'))
                && in_array('GET', $route->methods());
        });

        // Build sub-pages config from detected routes
        $areaSubPages = [];
        foreach ($areaRoutes as $route) {
            $uri = $route->uri();
            // Handle both {area} and {area:slug} parameter styles
            $subPath = preg_replace('/^areas\/\{area(:slug)?\}/', '', $uri);
            $subPath = $subPath ?: ''; // main area page
            
            // Set priority based on sub-page type
            $priority = match($subPath) {
                '' => 0.7,
                '/testimonials' => 0.6,
                '/projects' => 0.6,
                '/contact' => 0.6,
                '/about' => 0.5,
                default => 0.5,
            };
            
            $areaSubPages[$subPath] = [
                'priority' => $priority,
                'freq' => Url::CHANGE_FREQUENCY_MONTHLY,
            ];
            
            $this->line("  Found area route: /areas/{slug}{$subPath}");
        }

        $this->info("Found " . count($areaSubPages) . " area sub-page types for " . $areas->count() . " areas");

        foreach ($areas as $area) {
            foreach ($areaSubPages as $subPage => $config) {
                $sitemap->add(
                    Url::create("{$baseUrl}/areas/{$area->slug}{$subPage}")
                        ->setLastModificationDate($area->updated_at ?? now())
                        ->setChangeFrequency($config['freq'])
                        ->setPriority($config['priority'])
                );
                $urlCount++;
            }
        }

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
        $this->info("  - Area pages: " . ($areas->count() * count($areaSubPages)));

        return Command::SUCCESS;
    }
}
