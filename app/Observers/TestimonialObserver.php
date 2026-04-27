<?php

namespace App\Observers;

use App\Jobs\SubmitUrlsToIndexNow;
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

            SubmitUrlsToIndexNow::dispatch($urls)->onQueue('default')->delay(now()->addSeconds(15));
        } catch (\Exception $e) {
            Log::warning('IndexNow: Failed to queue testimonial URL submission', [
                'testimonial_id' => $testimonial->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
