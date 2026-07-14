<?php

namespace App\Livewire;

use App\Services\SeoService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Trade-partners hub (/trades): how GS Construction, as the general
 * contractor, works with its bench of skilled trade partners — with a card
 * per trade linking to the detail page.
 */
#[Layout('components.layouts.app')]
class TradesIndexPage extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $trades = [];

    public string $intro = '';

    public function mount(): void
    {
        if (! (bool) config('trades.enabled', true)) {
            abort(404);
        }

        $this->trades = (array) config('trades.trades', []);
        $this->intro = (string) config('trades.intro', '');

        SeoService::tradesIndex();
    }

    public function render()
    {
        return view('livewire.trades-index-page');
    }
}
