<?php

namespace App\Observers;

use App\Models\AreaServed;
use App\Services\IndexNowService;
use Illuminate\Support\Facades\Log;

class AreaServedObserver
{
    public function __construct(
        protected IndexNowService $indexNow
    ) {}

    public function created(AreaServed $area): void
    {
        $this->submitToIndexNow($area);
    }

    public function updated(AreaServed $area): void
    {
        $this->submitToIndexNow($area);
    }

    public function deleted(AreaServed $area): void
    {
        $this->submitToIndexNow($area);
    }

    protected function submitToIndexNow(AreaServed $area): void
    {
        if (! config('indexnow.auto_submit', true)) {
            return;
        }

        try {
            $urls = [
                route('areas.show', $area),
                route('areas.index'),
            ];

            // Include all sub-pages for this area
            foreach (['contact', 'testimonials', 'projects', 'about', 'services'] as $page) {
                $urls[] = route('areas.page', ['area' => $area, 'page' => $page]);
            }

            $this->indexNow->submitBatch($urls);
        } catch (\Exception $e) {
            Log::warning('IndexNow: Failed to submit area URL', [
                'area_id' => $area->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
