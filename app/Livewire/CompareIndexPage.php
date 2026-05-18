<?php

namespace App\Livewire;

use App\Services\SeoService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class CompareIndexPage extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $competitors = [];

    public function mount(): void
    {
        if (! (bool) config('competitors.enabled', true)) {
            abort(404);
        }

        $this->competitors = (array) config('competitors.competitors', []);

        SeoService::compareIndex();
    }

    public function render()
    {
        return view('livewire.compare-index-page');
    }
}
