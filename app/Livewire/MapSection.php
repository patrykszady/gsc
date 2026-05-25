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

        return view('livewire.map-section', [
            'area' => $this->area,
            'zipCounts' => $zipCounts,
            'maxCount' => $maxCount,
            'mapCenter' => [
                'lat' => 42.0907,
                'lng' => -87.9756,
            ],
            'heightClasses' => $this->heightClasses,
        ]);
    }
}
