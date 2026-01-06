<?php

namespace App\Livewire;

use App\Models\AreaServed;
use Livewire\Component;

class MapSection extends Component
{
    public ?AreaServed $area = null;

    public function render()
    {
        return view('livewire.map-section', [
            'area' => $this->area,
        ]);
    }
}
