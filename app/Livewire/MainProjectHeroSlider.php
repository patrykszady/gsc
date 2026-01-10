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

    /**
     * Get image data with blur placeholder for a project type.
     */
    protected function randomCoverDataForType(string $projectType, ?int $excludeImageId = null): ?array
    {
        $image = $this->randomCoverForType($projectType, $excludeImageId);
        
        if (!$image) {
            return null;
        }
        
        return $this->buildImageData($image);
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
            ->map(fn ($image) => $this->buildImageData($image))
            ->toArray();

        // Pad with fallback if we don't have enough images
        while (count($images) < $count) {
            $images[] = $this->buildFallbackImageData($fallback);
        }

        return $images;
    }

    /**
     * Build image data array with blur placeholder, full-size URL, and responsive srcset.
     */
    protected function buildImageData(ProjectImage $image): array
    {
        // Get URLs for different sizes
        $largeUrl = $image->getWebpThumbnailUrl('large') ?? $image->getThumbnailUrl('large') ?? $image->url;
        $mediumUrl = $image->getWebpThumbnailUrl('medium') ?? $image->getThumbnailUrl('medium');
        $smallUrl = $image->getWebpThumbnailUrl('small') ?? $image->getThumbnailUrl('small');
        $thumbUrl = $image->getWebpThumbnailUrl('thumb') ?? $image->getThumbnailUrl('thumb');
        
        return [
            'url' => $largeUrl,
            'medium' => $mediumUrl,
            'small' => $smallUrl,
            'thumb' => $thumbUrl,
            'alt' => $image->seo_alt_text,
            // Srcset for responsive images
            'srcset' => implode(', ', array_filter([
                $smallUrl ? "{$smallUrl} 300w" : null,
                $mediumUrl ? "{$mediumUrl} 600w" : null,
                $largeUrl ? "{$largeUrl} 2400w" : null,
            ])),
        ];
    }

    /**
     * Build fallback image data (Unsplash images support URL params for sizing).
     */
    protected function buildFallbackImageData(string $url): array
    {
        // For Unsplash, we can use URL params for different sizes
        $thumbUrl = str_replace(['w=1920', 'q=80'], ['w=50', 'q=30'], $url);
        $smallUrl = str_replace(['w=1920', 'q=80'], ['w=640', 'q=75'], $url);
        $mediumUrl = str_replace(['w=1920', 'q=80'], ['w=1024', 'q=80'], $url);
        
        return [
            'url' => $url,
            'medium' => $mediumUrl,
            'small' => $smallUrl,
            'thumb' => $thumbUrl,
            'alt' => 'Home remodeling project by GS Construction',
            'srcset' => "{$smallUrl} 640w, {$mediumUrl} 1024w, {$url} 1920w",
        ];
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
                $imageData = $images[$index] ?? $this->buildFallbackImageData($this->getFallbackForType($this->projectType));
                $slide['image'] = $imageData['url'];
                $slide['thumb'] = $imageData['thumb'];
                $slide['imageAlt'] = $imageData['alt'];
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
                $imageData = $this->randomCoverDataForType($type) ?? $this->buildFallbackImageData($this->getFallbackForType($type));
                $slide['image'] = $imageData['url'];
                $slide['thumb'] = $imageData['thumb'];
                $slide['imageAlt'] = $imageData['alt'];
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
        $slideImageData = [
            'kitchen' => $this->randomCoverDataForType('kitchen')
                ?? $this->buildFallbackImageData('https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=1920&q=80'),
            'bathroom' => $this->randomCoverDataForType('bathroom')
                ?? $this->buildFallbackImageData('https://images.unsplash.com/photo-1552321554-5fefe8c9ef14?w=1920&q=80'),
            'mudroom' => $this->randomCoverDataForType('mudroom')
                ?? $this->buildFallbackImageData('https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=1920&q=80'),
            'basement' => $this->randomCoverDataForType('basement')
                ?? $this->buildFallbackImageData('https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80'),
            'home-remodel' => $this->randomCoverDataForType('home-remodel')
                ?? $this->buildFallbackImageData('https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80'),
        ];

        // Merge slide images into slides config
        $renderedSlides = collect($this->slides)->map(function ($slide) use ($slideImageData) {
            $imageData = $slideImageData[$slide['projectType']] ?? $this->buildFallbackImageData('https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80');
            $slide['image'] = $imageData['url'];
            $slide['thumb'] = $imageData['thumb'];
            $slide['imageAlt'] = $imageData['alt'];
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
