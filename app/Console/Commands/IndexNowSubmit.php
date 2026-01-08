<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Testimonial;
use App\Services\IndexNowService;
use Illuminate\Console\Command;

class IndexNowSubmit extends Command
{
    protected $signature = 'indexnow:submit 
                            {--url=* : Specific URLs to submit}
                            {--all : Submit all public URLs (sitemap)}
                            {--models : Submit all model URLs (projects, testimonials, areas)}';

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
        }

        // Collect model URLs
        if ($this->option('models')) {
            $urls = array_merge($urls, $this->getModelUrls());
        }

        // If no options specified, show help
        if (empty($urls)) {
            $this->info('Usage examples:');
            $this->line('  php artisan indexnow:submit --url="https://example.com/page"');
            $this->line('  php artisan indexnow:submit --url="/projects" --url="/about"');
            $this->line('  php artisan indexnow:submit --models');
            $this->line('  php artisan indexnow:submit --all');

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

        // Projects don't have individual pages based on routes, skip for now
        // Add if you have individual project pages in the future

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
                // Include sub-pages
                foreach (['contact', 'testimonials', 'projects', 'about', 'services'] as $page) {
                    $urls[] = route('areas.page', ['area' => $area, 'page' => $page]);
                }
            }
        }

        return $urls;
    }
}
