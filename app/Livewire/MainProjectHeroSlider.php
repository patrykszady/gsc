<?php

namespace App\Livewire;

use App\Models\ProjectImage;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class MainProjectHeroSlider extends Component
{
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

    public function randomHeroImage(string $projectType): ?string
    {
        $projectType = strtolower(trim($projectType));

        $fallback = match ($projectType) {
            'kitchen' => 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=1920&q=80',
            'bathroom' => 'https://images.unsplash.com/photo-1552321554-5fefe8c9ef14?w=1920&q=80',
            'mudroom' => 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=1920&q=80',
            'home-remodel' => 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80',
            default => null,
        };

        // Avoid repeating the same image when a type has multiple covers.
        $lastIdKey = "hero.last-image-id.{$projectType}.v3";
        $excludeId = Cache::get($lastIdKey);

        $image = $this->randomCoverForType($projectType, is_int($excludeId) ? $excludeId : null)
            ?? $this->randomCoverForType($projectType);

        if (! $image) {
            return $fallback;
        }

        Cache::put($lastIdKey, $image->id, now()->addHours(6));

        return $image->url;
    }

    public function render()
    {
        $slideImages = Cache::remember('hero.slide-images.v3', now()->addMinutes(30), function () {
            return [
                'kitchen' => $this->randomCoverUrlForType('kitchen')
                    ?? 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=1920&q=80',
                'bathroom' => $this->randomCoverUrlForType('bathroom')
                    ?? 'https://images.unsplash.com/photo-1552321554-5fefe8c9ef14?w=1920&q=80',
                'mudrooms' => $this->randomCoverUrlForType('mudroom')
                    ?? 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=1920&q=80',
                'mudroom' => $this->randomCoverUrlForType('mudroom')
                    ?? 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=1920&q=80',
                'home-remodels' => $this->randomCoverUrlForType('home-remodel')
                    ?? 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80',
                'home-remodel' => $this->randomCoverUrlForType('home-remodel')
                    ?? 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80',
            ];
        });

        return view('livewire.main-project-hero-slider', [
            'slideImages' => $slideImages,
        ]);
    }
}
