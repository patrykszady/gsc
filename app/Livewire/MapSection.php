<?php

namespace App\Livewire;

use App\Models\AreaServed;
use Livewire\Component;
use Illuminate\Support\Str;

class MapSection extends Component
{
    public ?AreaServed $area = null;

    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            <section class="relative mt-8 h-[250px] overflow-hidden sm:h-[300px] lg:h-[350px]">
                <div class="absolute inset-0 bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
            </section>
        </div>
        HTML;
    }

    public function render()
    {
        $zipCounts = $this->loadZipCountsFromCsv();
        $maxCount = $zipCounts->max('count') ?? 0;

        return view('livewire.map-section', [
            'area' => $this->area,
            'zipCounts' => $zipCounts,
            'maxCount' => $maxCount,
            'mapCenter' => [
                'lat' => 42.0907,
                'lng' => -87.9756,
            ],
        ]);
    }

    protected function loadZipCountsFromCsv()
    {
        $path = public_path('gs-projects.csv');
        if (!is_file($path)) {
            return collect();
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return collect();
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return collect();
        }

        $columns = collect($header)->map(fn ($col) => Str::of($col)->trim()->lower()->toString());
        $zipIndex = $columns->search('zip_code');

        if ($zipIndex === false) {
            fclose($handle);
            return collect();
        }

        $counts = [];
        while (($row = fgetcsv($handle)) !== false) {
            $zip = $row[$zipIndex] ?? '';
            $zip = trim((string) $zip);
            if ($zip === '' || $zip === '\\N') {
                continue;
            }

            $zip = preg_replace('/\s+/', '', $zip);
            $zip = substr($zip, 0, 10);
            if ($zip === '') {
                continue;
            }

            $counts[$zip] = ($counts[$zip] ?? 0) + 1;
        }

        fclose($handle);

        return collect($counts)
            ->map(fn ($count, $zip) => ['zip' => $zip, 'count' => $count])
            ->sortByDesc('count')
            ->values();
    }
}
