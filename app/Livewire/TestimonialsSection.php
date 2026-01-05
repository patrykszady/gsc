<?php

namespace App\Livewire;

use App\Models\ProjectImage;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Component;

class TestimonialsSection extends Component
{
    public array $current = [];

    public array $recentIds = [];

    public function mount(): void
    {
        // Load initial testimonial (prioritize recent ones from last 4 years)
        $recentCutoff = now()->subYears(4)->startOfDay();

        $testimonial = Testimonial::query()
            ->whereNotNull('review_date')
            ->where('review_date', '>=', $recentCutoff)
            ->inRandomOrder()
            ->first();

        // Fallback to any testimonial if no recent ones
        if (! $testimonial) {
            $testimonial = Testimonial::query()
                ->inRandomOrder()
                ->first();
        }

        if ($testimonial) {
            $this->current = $this->formatTestimonial($testimonial);
            $this->addToRecent($testimonial->id);
        }
    }

    public function nextTestimonial(): void
    {
        $this->loadRandomTestimonial();
    }

    public function prevTestimonial(): void
    {
        $this->loadRandomTestimonial();
    }

    protected function loadRandomTestimonial(): void
    {
        $testimonial = Testimonial::query()
            ->whereNotIn('id', $this->recentIds)
            ->inRandomOrder()
            ->first();

        // If all testimonials have been shown recently, reset and pick any
        if (! $testimonial) {
            $this->recentIds = [];
            $testimonial = Testimonial::query()
                ->inRandomOrder()
                ->first();
        }

        if ($testimonial) {
            $this->current = $this->formatTestimonial($testimonial);
            $this->addToRecent($testimonial->id);
        }
    }

    protected function addToRecent(int $id): void
    {
        $this->recentIds[] = $id;

        // Keep only the last 12
        if (count($this->recentIds) > 12) {
            $this->recentIds = array_slice($this->recentIds, -12);
        }
    }

    protected function formatTestimonial(Testimonial $testimonial): array
    {
        $projectType = $this->normalizeProjectType($testimonial->project_type);

        $imageUrl = null;
        if ($projectType) {
            $cacheKey = "testimonial.project-image.{$projectType}.v1";

            $imageUrl = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($projectType) {
                $image = ProjectImage::query()
                    ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
                    ->inRandomOrder()
                    ->first();

                // Testimonials display a square image; use a square thumbnail.
                return $image?->getThumbnailUrl('medium');
            });
        }

        return [
            'id' => $testimonial->id,
            'slug' => Str::slug($testimonial->reviewer_name.'-'.$testimonial->id),
            'name' => $testimonial->reviewer_name,
            'location' => $testimonial->project_location,
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
            'home-remodels', 'home remodels', 'whole-home', 'whole home' => 'whole-home',
            'additions', 'addition' => 'addition',
            'exteriors', 'exterior' => 'exterior',
            'decks', 'deck', 'patios', 'patio' => 'deck',
            default => 'other',
        };
    }

    public function render()
    {
        return view('livewire.testimonials-section');
    }
}
