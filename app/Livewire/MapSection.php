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
        $zipPoints = $hive->storedZipPoints();
        $maxCount = $zipPoints->max('count') ?? 0;

        // Default center = Chicagoland (Niles area). When this component is
        // mounted inside an area-served page, recenter on that city.
        $mapCenter = ['lat' => 42.0907, 'lng' => -87.9756];
        if ($this->area && $this->area->latitude !== null && $this->area->longitude !== null) {
            $mapCenter = [
                'lat' => (float) $this->area->latitude,
                'lng' => (float) $this->area->longitude,
            ];
        }

        // Crawlable per-town proof: the map bubbles are JS-only, so surface the
        // same Hive completed-project data as text search engines (and AI
        // answers) can read. Counts are real jobs from the PM system, computed
        // within a radius of this town's center via haversine.
        $proofStats = null;
        if ($this->area && $this->area->latitude !== null && $zipPoints->isNotEmpty()) {
            $lat = (float) $this->area->latitude;
            $lng = (float) $this->area->longitude;
            $withinMiles = function (float $radius) use ($zipPoints, $lat, $lng): int {
                return (int) $zipPoints->filter(function ($p) use ($lat, $lng, $radius) {
                    $dLat = deg2rad($p['lat'] - $lat);
                    $dLng = deg2rad($p['lng'] - $lng);
                    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($p['lat'])) * sin($dLng / 2) ** 2;

                    return 3959 * 2 * atan2(sqrt($a), sqrt(1 - $a)) <= $radius;
                })->sum('count');
            };

            $radius = 10;
            $nearby = $withinMiles($radius);
            if ($nearby === 0) {
                $radius = 15;
                $nearby = $withinMiles($radius);
            }

            $proofStats = [
                'nearby' => $nearby,
                'radius' => $radius,
                'total' => (int) $zipPoints->sum('count'),
                'zips' => $zipPoints->count(),
            ];
        }

        return view('livewire.map-section', [
            'area' => $this->area,
            'zipPoints' => $zipPoints,
            'maxCount' => $maxCount,
            'mapCenter' => $mapCenter,
            'heightClasses' => $this->heightClasses,
            'proofStats' => $proofStats,
        ]);
    }
}
