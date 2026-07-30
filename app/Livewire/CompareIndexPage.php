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

        $competitors = (array) config('competitors.competitors', []);

        // Shuffled per page load so no competitor is permanently buried at the
        // bottom of the grid. Done in mount(), not render(), so the order stays
        // stable for the life of the component instead of reshuffling on every
        // Livewire round-trip. Every link is still in the HTML, so crawling is
        // unaffected.
        shuffle($competitors);

        $this->competitors = $competitors;

        SeoService::compareIndex();
    }

    public function render()
    {
        return view('livewire.compare-index-page');
    }
}
