<?php

namespace App\Observers;

use App\Models\Testimonial;
use App\Services\IndexNowService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class TestimonialObserver
{
    public function __construct(
        protected IndexNowService $indexNow
    ) {}

    public function created(Testimonial $testimonial): void
    {
        $this->regenerateSitemap();
        $this->submitToIndexNow($testimonial);
    }

    public function updated(Testimonial $testimonial): void
    {
        $this->regenerateSitemap();
        $this->submitToIndexNow($testimonial);
    }

    public function deleted(Testimonial $testimonial): void
    {
        $this->regenerateSitemap();
        $this->submitToIndexNow($testimonial);
    }

    protected function regenerateSitemap(): void
    {
        try {
            Artisan::call('sitemap:generate');
        } catch (\Exception $e) {
            Log::warning('TestimonialObserver: Failed to regenerate sitemap', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function submitToIndexNow(Testimonial $testimonial): void
    {
        if (! config('indexnow.auto_submit', true)) {
            return;
        }

        try {
            $urls = [
                route('testimonials.show', $testimonial),
                route('testimonials.index'),
            ];

            $this->indexNow->submitBatch($urls);
        } catch (\Exception $e) {
            Log::warning('IndexNow: Failed to submit testimonial URL', [
                'testimonial_id' => $testimonial->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
