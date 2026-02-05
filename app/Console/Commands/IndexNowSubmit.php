<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Testimonial;
use App\Services\IndexNowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class IndexNowSubmit extends Command
{
    protected $signature = 'indexnow:submit 
                            {--url=* : Specific URLs to submit}
                            {--all : Submit all public URLs (sitemap + models + static)}
                            {--models : Submit all model URLs (projects, testimonials, areas)}
                            {--sitemap= : Submit URLs from a sitemap file or URL (defaults to public/sitemap.xml)}';

    protected $description = 'Submit URLs to IndexNow for faster search engine indexing';

    public function __construct(
        protected IndexNowService $indexNow
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->indexNow->isEnabled()) {
            $this->error('IndexNow is not enabled. Set INDEXNOW_ENABLED=true and INDEXNOW_KEY in your .env file.');

            return self::FAILURE;
        }

        $urls = [];

        // Collect specific URLs from --url option
        if ($this->option('url')) {
            $urls = array_merge($urls, $this->option('url'));
        }

        // Collect all static page URLs
        if ($this->option('all')) {
            $urls = array_merge($urls, $this->getStaticUrls());
            $urls = array_merge($urls, $this->getModelUrls());
            $urls = array_merge($urls, $this->getSitemapUrls($this->option('sitemap')));
        }

        // Collect model URLs
        if ($this->option('models')) {
            $urls = array_merge($urls, $this->getModelUrls());
        }

        if ($this->option('sitemap')) {
            $urls = array_merge($urls, $this->getSitemapUrls($this->option('sitemap')));
        }

        // If no options specified, show help
        if (empty($urls)) {
            $this->info('Usage examples:');
            $this->line('  php artisan indexnow:submit --url="https://example.com/page"');
            $this->line('  php artisan indexnow:submit --url="/projects" --url="/about"');
            $this->line('  php artisan indexnow:submit --models');
            $this->line('  php artisan indexnow:submit --all');
            $this->line('  php artisan indexnow:submit --sitemap="https://example.com/sitemap.xml"');

            return self::SUCCESS;
        }

        $urls = array_unique($urls);

        $this->info('Submitting ' . count($urls) . ' URLs to IndexNow...');

        if ($this->output->isVerbose()) {
            foreach ($urls as $url) {
                $this->line("  - {$url}");
            }
        }

        $success = $this->indexNow->submitBatch($urls);

        if ($success) {
            $this->info('✓ URLs submitted successfully!');

            return self::SUCCESS;
        }

        $this->error('Failed to submit URLs. Check the logs for details.');

        return self::FAILURE;
    }

    /**
     * Get all static page URLs
     */
    protected function getStaticUrls(): array
    {
        return [
            route('home'),
            route('about'),
            route('contact'),
            route('projects.index'),
            route('testimonials.index'),
            route('services.index'),
            route('services.kitchen'),
            route('services.bathroom'),
            route('services.home'),
            route('areas.index'),
        ];
    }

    /**
     * Get URLs for all models (projects, testimonials, areas)
     */
    protected function getModelUrls(): array
    {
        $urls = [];

        // Projects
        if (class_exists(Project::class)) {
            $projects = Project::all();
            foreach ($projects as $project) {
                $urls[] = route('projects.show', $project);
            }
        }

        // Testimonials
        if (class_exists(Testimonial::class)) {
            $testimonials = Testimonial::all();
            foreach ($testimonials as $testimonial) {
                $urls[] = route('testimonials.show', $testimonial);
            }
        }

        // Areas served
        if (class_exists(AreaServed::class)) {
            $areas = AreaServed::all();
            foreach ($areas as $area) {
                $urls[] = route('areas.show', $area);
                // Include sub-pages (generic pages)
                foreach (['contact', 'testimonials', 'projects', 'about', 'services'] as $page) {
                    $urls[] = route('areas.page', ['area' => $area, 'page' => $page]);
                }
                // Include service-specific pages for each area
                foreach (['kitchen-remodeling', 'bathroom-remodeling', 'home-remodeling'] as $service) {
                    $urls[] = route('areas.page', ['area' => $area, 'page' => $service]);
                }
            }
        }

        return $urls;
    }

    /**
     * Get URLs from a sitemap file or URL.
     */
    protected function getSitemapUrls(?string $source = null): array
    {
        $source = $source ?: public_path('sitemap.xml');

        $xml = $this->loadSitemapXml($source);
        if (! $xml) {
            return [];
        }

        $urls = [];

        if (isset($xml->sitemap)) {
            foreach ($xml->sitemap as $sitemap) {
                $loc = (string) $sitemap->loc;
                if ($loc) {
                    $urls = array_merge($urls, $this->getSitemapUrls($loc));
                }
            }
        }

        if (isset($xml->url)) {
            foreach ($xml->url as $url) {
                $loc = (string) $url->loc;
                if ($loc) {
                    $urls[] = $loc;
                }
            }
        }

        return $urls;
    }

    /**
     * Load sitemap XML from a local path or URL.
     */
    protected function loadSitemapXml(string $source): ?\SimpleXMLElement
    {
        try {
            if (filter_var($source, FILTER_VALIDATE_URL)) {
                $response = Http::timeout(20)->get($source);
                if (! $response->successful()) {
                    $this->warn("Failed to fetch sitemap URL: {$source}");
                    return null;
                }
                $content = $response->body();
            } else {
                if (! file_exists($source)) {
                    $this->warn("Sitemap file not found: {$source}");
                    return null;
                }
                $content = file_get_contents($source);
            }

            if (! $content) {
                $this->warn("Empty sitemap content: {$source}");
                return null;
            }

            $xml = @simplexml_load_string($content);

            if (! $xml) {
                $this->warn("Failed to parse sitemap XML: {$source}");
                return null;
            }

            return $xml;
        } catch (\Throwable $e) {
            $this->warn("Error loading sitemap {$source}: {$e->getMessage()}");
            return null;
        }
    }
}
