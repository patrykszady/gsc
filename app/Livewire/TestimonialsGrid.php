<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\ProjectImage;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Cache;
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
            ->visible()
            ->whereNotNull('review_date')
            ->where('review_date', '>=', $recentCutoff)
            ->with('projects:id')
            ->inRandomOrder()
            ->take(10)
            ->get();
        
        $recentIds = $recentTestimonials->pluck('id')->toArray();
        
        // Then random older ones
        $olderTestimonials = Testimonial::query()
            ->visible()
            ->whereNotIn('id', $recentIds)
            ->with('projects:id')
            ->inRandomOrder()
            ->get();
        
        // Combine: recent first, then older
        $allTestimonials = $recentTestimonials->concat($olderTestimonials);
        
        $usedImageIds = [];

        $testimonials = $allTestimonials
            ->map(fn ($t) => $this->formatTestimonial($t, $usedImageIds))
            ->values();

        // Pick a random featured testimonial from recent ones (biased toward longer descriptions).
        $recentFormatted = $testimonials
            ->whereIn('id', $recentIds)
            ->values();

        $featuredPoolSource = $recentFormatted;
        if ($this->area) {
            $areaSlug = $this->area->slug;
            $areaFeatured = $recentFormatted->filter(fn ($t) => $t['area_slug'] === $areaSlug);
            if ($areaFeatured->isNotEmpty()) {
                $featuredPoolSource = $areaFeatured;
            }
        }

        $featuredPool = $featuredPoolSource
            ->sortByDesc(fn ($t) => strlen($t['description'] ?? ''))
            ->take(6)
            ->values();

        $featured = ($featuredPool->isNotEmpty() ? $featuredPool : $testimonials)->random();

        if ($this->area) {
            $areaSlug = $this->area->slug;
            [$areaFirst, $other] = $testimonials->partition(fn ($t) => $t['area_slug'] === $areaSlug);
            $testimonials = $areaFirst->concat($other)->values();
        }

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

    protected function formatTestimonial(Testimonial $testimonial, array &$usedImageIds): array
    {
        $projectType = $this->normalizeProjectType($testimonial->project_type);

        $image = $this->linkedProjectImage($testimonial, $usedImageIds);

        if ($projectType) {
            $image ??= ProjectImage::query()
                    ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
                    ->whereNotIn('id', $usedImageIds)
                    ->inRandomOrder()
                    ->first();

            if (! $image) {
                $image = ProjectImage::query()
                    ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
                    ->inRandomOrder()
                    ->first();
            }
        }

        if (! $image) {
            $image = ProjectImage::query()
                ->whereHas('project', fn ($q) => $q->published())
                ->whereNotIn('id', $usedImageIds)
                ->inRandomOrder()
                ->first();
        }

        if (! $image) {
            $image = ProjectImage::query()
                ->whereHas('project', fn ($q) => $q->published())
                ->inRandomOrder()
                ->first();
        }

        if ($image) {
            $usedImageIds[] = $image->id;
        }

        // Always prefer a real project image over generated initials avatars.
        $imageUrl = $image?->getThumbnailUrl('medium') ?: $this->fallbackProjectImageUrl();

        // Extract city name (strip ", IL" or similar state suffix)
        $cityName = preg_replace('/,\s*[A-Z]{2}$/', '', $testimonial->project_location);

        return [
            'id' => $testimonial->id,
            'slug' => $testimonial->slug,
            'name' => $testimonial->display_name,
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

    protected function fallbackProjectImageUrl(): string
    {
        return Cache::remember('testimonials.fallback-project-image.medium.v1', now()->addMinutes(30), function () {
            $image = ProjectImage::query()
                ->whereHas('project', fn ($q) => $q->published())
                ->inRandomOrder()
                ->first();

            return $image?->getThumbnailUrl('medium') ?: asset('images/greg-patryk-thumb.jpg');
        });
    }

    protected function linkedProjectImage(Testimonial $testimonial, array &$usedImageIds): ?ProjectImage
    {
        $linkedProjectIds = collect([$testimonial->project_id])
            ->filter()
            ->merge($testimonial->projects->pluck('id'))
            ->unique()
            ->values();

        if ($linkedProjectIds->isEmpty()) {
            return null;
        }

        $image = ProjectImage::query()
            ->whereIn('project_id', $linkedProjectIds)
            ->where('is_cover', true)
            ->whereNotIn('id', $usedImageIds)
            ->inRandomOrder()
            ->first();

        $image ??= ProjectImage::query()
            ->whereIn('project_id', $linkedProjectIds)
            ->whereNotIn('id', $usedImageIds)
            ->inRandomOrder()
            ->first();

        // If linked pool is too small to stay unique, still prefer linked images.
        $image ??= ProjectImage::query()
            ->whereIn('project_id', $linkedProjectIds)
            ->where('is_cover', true)
            ->inRandomOrder()
            ->first();

        $image ??= ProjectImage::query()
            ->whereIn('project_id', $linkedProjectIds)
            ->inRandomOrder()
            ->first();

        return $image;
    }
}
