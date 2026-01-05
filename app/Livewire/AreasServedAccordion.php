<?php

namespace App\Livewire;

use App\Models\AreaServed;
use Livewire\Component;

class AreasServedAccordion extends Component
{
    public function render()
    {
        return view('livewire.areas-served-accordion', [
            'areas' => AreaServed::orderBy('city')->get(),
        ]);
    }
}
