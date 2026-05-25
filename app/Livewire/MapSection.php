<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Services\HiveProjectsClient;
use Livewire\Component;

class MapSection extends Component
{
    public ?AreaServed $area = null;
    public string $heightClasses = 'h-[250px] sm:h-[300px] lg:h-[350px]';

    public function placeholder(): string
    {
        $heightClasses = $this->heightClasses;
        return <<<HTML
        <div>
            <section class="relative mt-8 overflow-hidden {$heightClasses}">
                <div class="absolute inset-0 bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
            </section>
        </div>
        HTML;
    }

    public function render(HiveProjectsClient $hive)
    {
        $zipCounts = $hive->storedZipCounts();
        $maxCount = $zipCounts->max('count') ?? 0;

        // Default center = Chicagoland (Niles area). When this component is
        // mounted inside an area-served page, recenter on that city.
        $mapCenter = ['lat' => 42.0907, 'lng' => -87.9756];
        if ($this->area && $this->area->latitude !== null && $this->area->longitude !== null) {
            $mapCenter = [
                'lat' => (float) $this->area->latitude,
                'lng' => (float) $this->area->longitude,
            ];
        }

        return view('livewire.map-section', [
            'area' => $this->area,
            'zipCounts' => $zipCounts,
            'maxCount' => $maxCount,
            'mapCenter' => $mapCenter,
            'heightClasses' => $this->heightClasses,
        ]);
    }
}
