<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use Illuminate\Console\Command;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate {--url= : Base URL for the sitemap (defaults to APP_URL)}';

    protected $description = 'Generate the sitemap for the website';

    public function handle(): int
    {
        $baseUrl = $this->option('url') ?: config('app.url');
        
        $this->info("Generating sitemap with base URL: {$baseUrl}");

        $sitemap = Sitemap::create();

        // Add static pages
        $sitemap->add(
            Url::create("{$baseUrl}/")
                ->setLastModificationDate(now())
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->setPriority(1.0)
        );

        $sitemap->add(
            Url::create("{$baseUrl}/testimonials")
                ->setLastModificationDate(now())
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->setPriority(0.8)
        );

        // Add area-specific pages
        $areas = AreaServed::all();

        foreach ($areas as $area) {
            $sitemap->add(
                Url::create("{$baseUrl}/areas/{$area->slug}")
                    ->setLastModificationDate($area->updated_at ?? now())
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                    ->setPriority(0.7)
            );

            $sitemap->add(
                Url::create("{$baseUrl}/areas/{$area->slug}/testimonials")
                    ->setLastModificationDate($area->updated_at ?? now())
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                    ->setPriority(0.6)
            );
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

        $this->info('Total URLs: ' . (2 + ($areas->count() * 2)));

        return Command::SUCCESS;
    }
}
