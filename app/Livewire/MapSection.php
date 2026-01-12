<?php

namespace App\Livewire;

use App\Models\AreaServed;
use Livewire\Component;

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
        return view('livewire.map-section', [
            'area' => $this->area,
        ]);
    }
}
