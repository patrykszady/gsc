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
                'url' => $newImage->url,
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
            'url' => $img->url,
            'thumb' => $img->getWebpThumbnailUrl('thumb') ?? $img->getThumbnailUrl('thumb') ?? $img->url,
        ])->toArray();
    }

    public function render()
    {
        return view('livewire.team-photo-slider');
    }
}
