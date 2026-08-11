<?php

namespace App\Livewire\Admin;

use App\Models\Testimonial;
use App\Models\ReviewUrl;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
#[Title('Reviews')]
class TestimonialList extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $platform = '';

    #[Url]
    public string $star_rating = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatingPlatform(): void
    {
        $this->resetPage();
    }

    public function updatingStarRating(): void
    {
        $this->resetPage();
    }

    public function delete(int $id): void
    {
        Testimonial::findOrFail($id)->delete();

        session()->flash('success', 'Review deleted successfully.');
    }

    /**
     * Queue the Yelp review scrape (same command the Monday scheduler runs).
     * Queued because the stealth-browser/DataDome path can take 5+ minutes.
     */
    public function syncYelpReviews(): void
    {
        // media-sync, not social-media. social-media runs on supervisor-1 with
        // timeout=60 (config/horizon.php), so this job — which the docblock
        // above correctly describes as taking 5+ minutes — was SIGKILLed at 60
        // seconds every single time the button was pressed. The command's own
        // 300s process timeout could never even fire. media-sync is the
        // Puppeteer queue (timeout=360, maxProcesses=1) every other browser job
        // already uses, and its single process also serialises this against
        // photo uploads that share the Chromium profile.
        \Illuminate\Support\Facades\Artisan::queue('testimonials:sync-yelp-reviews', ['--only-new' => true])
            ->onQueue('media-sync');

        session()->flash('success', 'Yelp sync queued — new reviews will appear here within a few minutes. Check storage/logs for details.');
    }

    /** Queue the Google (GBP API) review sync — usually completes in seconds. */
    public function syncGoogleReviews(): void
    {
        \Illuminate\Support\Facades\Artisan::queue('google-business-profile:sync-reviews')
            ->onQueue('social-media');

        session()->flash('success', 'Google review sync queued — refresh shortly.');
    }

    public function render()
    {
        $query = Testimonial::query();

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('reviewer_name', 'like', "%{$this->search}%")
                    ->orWhere('review_description', 'like', "%{$this->search}%")
                    ->orWhere('project_location', 'like', "%{$this->search}%");
            });
        }

        if ($this->type) {
            $query->where('project_type', $this->type);
        }

        if ($this->platform) {
            if ($this->platform === 'google') {
                $query->whereHas('reviewUrls', function ($urlQuery) {
                    $urlQuery->where('platform', 'google')
                        ->whereNotNull('external_id')
                        ->where('external_id', '!=', '');
                });
            } else {
                $query->whereHas('reviewUrls', function ($q) {
                    $q->where('platform', $this->platform);
                });
            }
        }

        if ($this->star_rating !== '') {
            $query->where('star_rating', (int) $this->star_rating);
        }

        // Get unique project types for filter
        $projectTypes = Testimonial::whereNotNull('project_type')
            ->distinct()
            ->pluck('project_type')
            ->filter()
            ->sort()
            ->values();

        // Get unique review URL platforms for filter
        $platforms = ReviewUrl::query()
            ->distinct()
            ->pluck('platform')
            ->filter()
            ->sort()
            ->values();

        return view('livewire.admin.testimonial-list', [
            'testimonials' => $query->with('reviewUrls')->orderByDesc('review_date')->orderByDesc('created_at')->paginate(12),
            'projectTypes' => $projectTypes,
            'platforms' => $platforms,
        ]);
    }
}
