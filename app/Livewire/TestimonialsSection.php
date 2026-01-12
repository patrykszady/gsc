<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\ProjectImage;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Component;

class TestimonialsSection extends Component
{
    public ?AreaServed $area = null;

    public bool $showHeader = true;

    public ?string $projectType = null;

    public array $current = [];

    public array $shownIds = [];

    public array $history = [];

    public int $historyIndex = -1;

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="relative isolate bg-white py-16 sm:py-20 lg:py-24 dark:bg-slate-950">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center space-y-4">
                    <div class="h-4 w-24 mx-auto bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                    <div class="h-10 w-3/4 mx-auto bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                    <div class="h-6 w-full bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                </div>
                <div class="mt-12 flex justify-center gap-8">
                    <div class="w-72 h-80 bg-zinc-200 dark:bg-zinc-700 rounded-2xl animate-pulse"></div>
                    <div class="hidden md:block w-96 h-96 bg-zinc-200 dark:bg-zinc-700 rounded-2xl animate-pulse"></div>
                    <div class="hidden lg:block w-72 h-80 bg-zinc-200 dark:bg-zinc-700 rounded-2xl animate-pulse"></div>
                </div>
            </div>
        </div>
        HTML;
    }

    public function mount(): void
    {
        // Load 10 random reviews from the last 6 years as initial pool
        $recentCutoff = now()->subYears(6)->startOfDay();

        $initialReviews = Testimonial::query()
            ->whereNotNull('review_date')
            ->where('review_date', '>=', $recentCutoff)
            ->when($this->projectType, fn($q) => $q->where('project_type', 'LIKE', '%' . $this->projectType . '%'))
            ->inRandomOrder()
            ->take(10)
            ->get();

        // Fallback to any 10 random testimonials if no recent ones with this project type
        if ($initialReviews->isEmpty()) {
            $initialReviews = Testimonial::query()
                ->when($this->projectType, fn($q) => $q->where('project_type', 'LIKE', '%' . $this->projectType . '%'))
                ->inRandomOrder()
                ->take(10)
                ->get();
        }

        // Final fallback to any testimonials if none match project type
        if ($initialReviews->isEmpty()) {
            $initialReviews = Testimonial::query()
                ->inRandomOrder()
                ->take(10)
                ->get();
        }

        if ($initialReviews->isNotEmpty()) {
            // Format all initial reviews and add to history
            foreach ($initialReviews as $testimonial) {
                $this->history[] = $this->formatTestimonial($testimonial);
                $this->shownIds[] = $testimonial->id;
            }
            
            // Start with the first one
            $this->current = $this->history[0];
            $this->historyIndex = 0;
        }
    }

    public function nextTestimonial(): void
    {
        // If we're not at the end of history, move forward
        if ($this->historyIndex < count($this->history) - 1) {
            $this->historyIndex++;
            $this->current = $this->history[$this->historyIndex];
            return;
        }

        // Load a new random testimonial not yet shown (only from last 6 years)
        $recentCutoff = now()->subYears(6)->startOfDay();
        
        $testimonial = Testimonial::query()
            ->whereNotNull('review_date')
            ->where('review_date', '>=', $recentCutoff)
            ->when($this->projectType, fn($q) => $q->where('project_type', 'LIKE', '%' . $this->projectType . '%'))
            ->whereNotIn('id', $this->shownIds)
            ->inRandomOrder()
            ->first();

        // If all recent testimonials have been shown, wrap to beginning of history
        if (! $testimonial) {
            $this->historyIndex = 0;
            $this->current = $this->history[$this->historyIndex];
            return;
        }

        $this->current = $this->formatTestimonial($testimonial);
        $this->shownIds[] = $testimonial->id;
        
        // Add to history
        $this->history[] = $this->current;
        $this->historyIndex = count($this->history) - 1;
    }

    public function prevTestimonial(): void
    {
        // Move backward in history, wrap to end if at beginning
        if ($this->historyIndex > 0) {
            $this->historyIndex--;
            $this->current = $this->history[$this->historyIndex];
        } else {
            // Wrap to the end of history
            $this->historyIndex = count($this->history) - 1;
            $this->current = $this->history[$this->historyIndex];
        }
    }

    protected function formatTestimonial(Testimonial $testimonial): array
    {
        $projectType = $this->normalizeProjectType($testimonial->project_type);

        $imageUrl = null;
        if ($projectType) {
            $cacheKey = "testimonial.project-image.{$testimonial->id}.{$projectType}.v3";

            $imageUrl = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($projectType) {
                // Use any random image from any published project of this type.
                $image = ProjectImage::query()
                    ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
                    ->inRandomOrder()
                    ->first();

                // Testimonials display a square image; use a square thumbnail.
                return $image?->getThumbnailUrl('medium');
            });
        }

        // Extract city name (strip ", IL" or similar state suffix)
        $cityName = preg_replace('/,\s*[A-Z]{2}$/', '', $testimonial->project_location);

        return [
            'id' => $testimonial->id,
            'slug' => $testimonial->slug,
            'name' => $testimonial->reviewer_name,
            'location' => $testimonial->project_location,
            'area_slug' => AreaServed::where('city', $cityName)->value('slug'),
            'project_type' => $testimonial->project_type,
            'description' => $testimonial->review_description,
            'date' => $testimonial->review_date?->format('M Y'),
            'image' => $imageUrl,
        ];
    }

    protected function normalizeProjectType(?string $testimonialProjectType): ?string
    {
        if (! $testimonialProjectType) {
            return null;
        }

        $type = strtolower(trim($testimonialProjectType));

        return match ($type) {
            'kitchens', 'kitchen' => 'kitchen',
            'bathrooms', 'bathroom' => 'bathroom',
            'basements', 'basement' => 'basement',
            'home-remodel', 'home-remodels', 'home remodel', 'home remodels', 'whole-home', 'whole home' => 'home-remodel',
            'additions', 'addition' => 'addition',
            'mudroom', 'mudrooms', 'laundry', 'laundry room', 'laundry rooms', 'mudroom/laundry', 'mudroom / laundry' => 'mudroom',
            'exteriors', 'exterior' => 'exterior',
            default => null,
        };
    }

    public function render()
    {
        return view('livewire.testimonials-section', [
            'area' => $this->area,
        ]);
    }
}
