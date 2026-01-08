<?php

namespace App\Observers;

use App\Models\Testimonial;
use App\Services\IndexNowService;
use Illuminate\Support\Facades\Log;

class TestimonialObserver
{
    public function __construct(
        protected IndexNowService $indexNow
    ) {}

    public function created(Testimonial $testimonial): void
    {
        $this->submitToIndexNow($testimonial);
    }

    public function updated(Testimonial $testimonial): void
    {
        $this->submitToIndexNow($testimonial);
    }

    public function deleted(Testimonial $testimonial): void
    {
        // Notify IndexNow that the URL no longer exists
        // IndexNow will handle it appropriately
        $this->submitToIndexNow($testimonial);
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
