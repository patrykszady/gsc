<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\ProjectImage;
use App\Models\Testimonial;
use App\Services\SeoService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class TestimonialPage extends Component
{
    public Testimonial $testimonial;

    public function mount(Testimonial $testimonial): void
    {
        $this->testimonial = $testimonial;

        SeoService::testimonial($testimonial);
    }

    protected function getImageUrl(): string
    {
        $projectType = $this->normalizeProjectType($this->testimonial->project_type);

        if ($projectType) {
            $image = ProjectImage::query()
                ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
                ->inRandomOrder()
                ->first();

            if ($image) {
                return $image->getThumbnailUrl('medium');
            }
        }

        return 'https://ui-avatars.com/api/?name=' . urlencode($this->testimonial->reviewer_name) . '&background=0ea5e9&color=fff&size=256';
    }

    protected function getThumbnailUrl(): ?string
    {
        $projectType = $this->normalizeProjectType($this->testimonial->project_type);

        if ($projectType) {
            $image = ProjectImage::query()
                ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
                ->inRandomOrder()
                ->first();

            if ($image) {
                return $image->getWebpThumbnailUrl('large') ?? $image->getThumbnailUrl('large') ?? $image->url;
            }
        }

        return null;
    }

    protected function getThumbnailThumbUrl(): ?string
    {
        $projectType = $this->normalizeProjectType($this->testimonial->project_type);

        if ($projectType) {
            $image = ProjectImage::query()
                ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
                ->inRandomOrder()
                ->first();

            if ($image) {
                return $image->getWebpThumbnailUrl('thumb') ?? $image->getThumbnailUrl('thumb');
            }
        }

        return null;
    }

    protected function getAreaSlug(): ?string
    {
        $cityName = preg_replace('/,\s*[A-Z]{2}$/', '', $this->testimonial->project_location);
        return AreaServed::where('city', $cityName)->value('slug');
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
        return view('livewire.testimonial-page', [
            'imageUrl' => $this->getImageUrl(),
            'thumbnailUrl' => $this->getThumbnailUrl(),
            'thumbnailThumbUrl' => $this->getThumbnailThumbUrl(),
            'areaSlug' => $this->getAreaSlug(),
        ]);
    }
}
