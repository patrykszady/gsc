<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\ProjectImage;
use App\Models\Testimonial;
use Livewire\Component;

class TestimonialsGrid extends Component
{
    public ?AreaServed $area = null;

    public function render()
    {
        $testimonials = Testimonial::query()
            ->inRandomOrder()
            ->get()
            ->map(fn ($t) => $this->formatTestimonial($t));

        // Pick a random featured testimonial (biased toward longer descriptions).
        $featuredPool = $testimonials
            ->sortByDesc(fn ($t) => strlen($t['description'] ?? ''))
            ->take(6)
            ->values();

        $featured = ($featuredPool->isNotEmpty() ? $featuredPool : $testimonials)->random();

        // Remaining testimonials - already in random order.
        $others = $testimonials->reject(fn ($t) => $t['id'] === $featured['id'])->values();

        return view('livewire.testimonials-grid', [
            'featured' => $featured,
            'testimonials' => $others,
            'area' => $this->area,
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
