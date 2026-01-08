<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\ProjectImage;
use Livewire\Component;

class MainProjectHeroSlider extends Component
{
    public ?AreaServed $area = null;

    // Slides configuration (passed from parent view)
    public array $slides = [];

    // Custom content for service pages
    public ?string $projectType = null; // Filter to single project type
    public ?string $label = null;
    public ?string $primaryCtaText = null;
    public ?string $primaryCtaUrl = null;
    public ?string $secondaryCtaText = null;
    public ?string $secondaryCtaUrl = null;
    public int $slideCount = 3; // Number of slides for filtered mode

    protected function randomCoverForType(string $projectType, ?int $excludeImageId = null): ?ProjectImage
    {
        $query = ProjectImage::query()
            ->where('is_cover', true)
            ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
            ->when($excludeImageId, fn ($q) => $q->where('id', '!=', $excludeImageId));

        return $query->inRandomOrder()->first();
    }

    protected function randomCoverUrlForType(string $projectType, ?int $excludeImageId = null): ?string
    {
        return $this->randomCoverForType($projectType, $excludeImageId)?->url;
    }

    protected function getFilteredSlideImages(string $type, int $count): array
    {
        $fallback = $this->getFallbackForType($type);

        $images = ProjectImage::query()
            ->where('is_cover', true)
            ->whereHas('project', fn ($q) => $q->published()->ofType($type))
            ->inRandomOrder()
            ->limit($count)
            ->get()
            ->map(fn ($image) => $image->url)
            ->toArray();

        // Pad with fallback if we don't have enough images
        while (count($images) < $count) {
            $images[] = $fallback;
        }

        return $images;
    }

    protected function getFallbackForType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'kitchen' => 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=1920&q=80',
            'bathroom' => 'https://images.unsplash.com/photo-1552321554-5fefe8c9ef14?w=1920&q=80',
            'mudroom' => 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=1920&q=80',
            'home-remodel', 'basement' => 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80',
            default => 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80',
        };
    }

    public function render()
    {
        // When filtering to a specific project type (service pages)
        if ($this->projectType && $this->projectType !== 'mixed') {
            $images = $this->getFilteredSlideImages($this->projectType, $this->slideCount);
            
            // Merge images with slides (slides contain heading/subheading)
            $renderedSlides = collect($this->slides)->map(function ($slide, $index) use ($images) {
                $slide['image'] = $images[$index] ?? $this->getFallbackForType($this->projectType);
                return $slide;
            })->toArray();

            return view('livewire.main-project-hero-slider', [
                'renderedSlides' => $renderedSlides,
                'area' => $this->area,
                'mode' => 'service',
                'label' => $this->label,
                'primaryCtaText' => $this->primaryCtaText,
                'primaryCtaUrl' => $this->primaryCtaUrl,
                'secondaryCtaText' => $this->secondaryCtaText,
                'secondaryCtaUrl' => $this->secondaryCtaUrl,
                'projectType' => $this->projectType,
            ]);
        }

        // Mixed mode: each slide has its own type (services overview page)
        if ($this->projectType === 'mixed') {
            $renderedSlides = collect($this->slides)->map(function ($slide) {
                $type = $slide['type'] ?? 'home-remodel';
                $slide['image'] = $this->randomCoverUrlForType($type) ?? $this->getFallbackForType($type);
                return $slide;
            })->toArray();

            return view('livewire.main-project-hero-slider', [
                'renderedSlides' => $renderedSlides,
                'area' => $this->area,
                'mode' => 'service',
                'label' => $this->label,
                'primaryCtaText' => $this->primaryCtaText,
                'primaryCtaUrl' => $this->primaryCtaUrl,
                'secondaryCtaText' => $this->secondaryCtaText,
                'secondaryCtaUrl' => $this->secondaryCtaUrl,
                'projectType' => null,
            ]);
        }

        // Default mode: show all project types (home page)
        $slideImages = [
            'kitchen' => $this->randomCoverUrlForType('kitchen')
                ?? 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=1920&q=80',
            'bathroom' => $this->randomCoverUrlForType('bathroom')
                ?? 'https://images.unsplash.com/photo-1552321554-5fefe8c9ef14?w=1920&q=80',
            'mudroom' => $this->randomCoverUrlForType('mudroom')
                ?? 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=1920&q=80',
            'basement' => $this->randomCoverUrlForType('basement')
                ?? 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80',
            'home-remodel' => $this->randomCoverUrlForType('home-remodel')
                ?? 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80',
        ];

        // Merge slide images into slides config
        $renderedSlides = collect($this->slides)->map(function ($slide) use ($slideImages) {
            $slide['image'] = $slideImages[$slide['projectType']] ?? $slide['image'] ?? null;
            return $slide;
        })->toArray();

        return view('livewire.main-project-hero-slider', [
            'renderedSlides' => $renderedSlides,
            'area' => $this->area,
            'mode' => 'home',
            'heading' => null,
            'subheading' => null,
            'label' => null,
            'primaryCtaText' => null,
            'primaryCtaUrl' => null,
            'secondaryCtaText' => $this->secondaryCtaText,
            'secondaryCtaUrl' => $this->secondaryCtaUrl,
            'projectType' => null,
        ]);
    }
}
