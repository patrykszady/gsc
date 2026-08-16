<?php

namespace App\Livewire;

use App\Models\ProjectImage;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class TeamPhotoSlider extends Component
{
    public array $backgroundImages = [];

    public function mount(): void
    {
        $this->backgroundImages = $this->getRandomCoverImages(5);
    }

    public function refreshBackgroundImage(?int $index): ?string
    {
        if ($index === null) {
            return null;
        }

        // Avoid a DOM morph on each refresh call. The slider updates Alpine
        // state client-side using the returned URL, and morphing can briefly
        // evaluate bindings before Alpine scope is re-attached.
        $this->skipRender();

        // Get a new random image, excluding current ones
        $excludeIds = collect($this->backgroundImages)
            ->pluck('id')
            ->filter()
            ->toArray();

        $newImage = ProjectImage::query()
            ->where('is_cover', true)
            ->whereHas('project', fn ($q) => $q->published())
            ->whereNotIn('id', $excludeIds)
            ->inRandomOrder()
            ->first();

        if ($newImage) {
            $this->backgroundImages[$index] = [
                'id' => $newImage->id,
                'url' => $newImage->getWebpThumbnailUrl('medium') ?? $newImage->getThumbnailUrl('medium') ?? $newImage->url,
                'thumb' => $newImage->getWebpThumbnailUrl('thumb') ?? $newImage->getThumbnailUrl('thumb') ?? $newImage->url,
            ];
            return $newImage->url;
        }

        return null;
    }

    protected function getRandomCoverImages(int $count): array
    {
        $images = ProjectImage::query()
            ->where('is_cover', true)
            ->whereHas('project', fn ($q) => $q->published())
            ->inRandomOrder()
            ->limit($count)
            ->get();

        return $images->map(fn ($img) => [
            'id' => $img->id,
            // medium webp (~37KB) — this was the ORIGINAL upload (300KB-5MB per
            // slide), which made this decorative rotating box the single
            // heaviest thing on every page that renders it.
            'url' => $img->getWebpThumbnailUrl('medium') ?? $img->getThumbnailUrl('medium') ?? $img->url,
            'thumb' => $img->getWebpThumbnailUrl('thumb') ?? $img->getThumbnailUrl('thumb') ?? $img->url,
        ])->toArray();
    }

    public function render()
    {
        return view('livewire.team-photo-slider');
    }
}
