<?php

namespace App\Livewire;

use App\Models\AreaServed;
use Livewire\Component;

class AboutSection extends Component
{
    public ?AreaServed $area = null;

    public function render()
    {
        return view('livewire.about-section', [
            'area' => $this->area,
        ]);
    }
}
