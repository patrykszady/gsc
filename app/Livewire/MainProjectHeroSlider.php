<?php

namespace App\Livewire;

use App\Models\ProjectImage;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class MainProjectHeroSlider extends Component
{
    protected function randomCoverUrlForType(string $projectType): ?string
    {
        $cover = ProjectImage::query()
            ->where('is_cover', true)
            ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
            ->inRandomOrder()
            ->first();

        return $cover?->url;
    }

    public function render()
    {
        $slideImages = Cache::remember('hero.slide-images.v1', now()->addMinutes(30), function () {
            return [
                'kitchen' => $this->randomCoverUrlForType('kitchen')
                    ?? 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=1920&q=80',
                'bathroom' => $this->randomCoverUrlForType('bathroom')
                    ?? 'https://images.unsplash.com/photo-1552321554-5fefe8c9ef14?w=1920&q=80',
                // No dedicated "mudroom" type yet; treat as "other" for now.
                'mudrooms' => $this->randomCoverUrlForType('other')
                    ?? 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=1920&q=80',
                'mudroom' => $this->randomCoverUrlForType('other')
                    ?? 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=1920&q=80',
                // "Home Remodels" maps best to whole-home renovations.
                'home-remodels' => $this->randomCoverUrlForType('whole-home')
                    ?? 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80',
                'home-remodel' => $this->randomCoverUrlForType('whole-home')
                    ?? 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80',
            ];
        });

        return view('livewire.main-project-hero-slider', [
            'slideImages' => $slideImages,
        ]);
    }
}
