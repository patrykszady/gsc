<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\ProjectImage;
use App\Models\Testimonial;
use Livewire\Component;

class TestimonialsGrid extends Component
{
    public ?AreaServed $area = null;

    public bool $showHeader = true;

    public int $visibleRows = 3; // Start with 3 rows (top + row2 + row3)

    public function loadMore(): void
    {
        $this->visibleRows += 2; // Load 2 more rows each time
    }

    public function render()
    {
        // First 10 random from the last 6 years
        $recentCutoff = now()->subYears(6)->startOfDay();
        
        $recentTestimonials = Testimonial::query()
            ->whereNotNull('review_date')
            ->where('review_date', '>=', $recentCutoff)
            ->inRandomOrder()
            ->take(10)
            ->get();
        
        $recentIds = $recentTestimonials->pluck('id')->toArray();
        
        // Then random older ones
        $olderTestimonials = Testimonial::query()
            ->whereNotIn('id', $recentIds)
            ->inRandomOrder()
            ->get();
        
        // Combine: recent first, then older
        $allTestimonials = $recentTestimonials->concat($olderTestimonials);
        
        $testimonials = $allTestimonials
            ->map(fn ($t) => $this->formatTestimonial($t));

        // Pick a random featured testimonial from recent ones (biased toward longer descriptions).
        $recentFormatted = $recentTestimonials->map(fn ($t) => $this->formatTestimonial($t));
        
        $featuredPool = $recentFormatted
            ->sortByDesc(fn ($t) => strlen($t['description'] ?? ''))
            ->take(6)
            ->values();

        $featured = ($featuredPool->isNotEmpty() ? $featuredPool : $testimonials)->random();

        // Remaining testimonials - keep order (recent first).
        $others = $testimonials->reject(fn ($t) => $t['id'] === $featured['id'])->values();

        // Calculate how many testimonials we can show based on visible rows
        // Row 1: featured (2 cols) + leftTop + rightTop = 3 testimonials from $others (indices 0, 1)
        // Row 2: 4 testimonials (indices 2-5)
        // Row 3: 4 testimonials (indices 6-9)
        // Row 4+: 4 testimonials each
        $maxVisible = 2 + (($this->visibleRows - 1) * 4); // 2 for top row sides, then 4 per additional row
        $hasMore = $others->count() > $maxVisible;

        return view('livewire.testimonials-grid', [
            'featured' => $featured,
            'testimonials' => $others,
            'area' => $this->area,
            'visibleRows' => $this->visibleRows,
            'hasMore' => $hasMore,
        ]);
    }

    protected function formatTestimonial(Testimonial $testimonial): array
    {
        $projectType = $this->normalizeProjectType($testimonial->project_type);

        $imageUrl = null;
        if ($projectType) {
            $image = ProjectImage::query()
                ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
                ->inRandomOrder()
                ->first();

            $imageUrl = $image?->getThumbnailUrl('medium');
        }

        // Fallback to ui-avatars if no project image.
        $imageUrl ??= 'https://ui-avatars.com/api/?name=' . urlencode($testimonial->reviewer_name) . '&background=0ea5e9&color=fff&size=128';

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
}
