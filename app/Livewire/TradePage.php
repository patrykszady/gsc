<?php

namespace App\Livewire;

use App\Services\SeoService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Single trade-partner page (/trades/{slug}): what this trade does on a GS
 * remodel, when a project needs them, how GS vets and supervises them.
 */
#[Layout('components.layouts.app')]
class TradePage extends Component
{
    /** @var array<string, mixed> */
    public array $trade = [];

    /** @var array<int, array<string, mixed>> */
    public array $otherTrades = [];

    public function mount(string $slug): void
    {
        if (! (bool) config('trades.enabled', true)) {
            abort(404);
        }

        $all = collect((array) config('trades.trades', []));
        $trade = $all->firstWhere('slug', $slug);

        if (! $trade) {
            abort(404);
        }

        $this->trade = $trade;
        $this->otherTrades = $all
            ->where('slug', '!=', $slug)
            ->map(fn (array $t) => ['slug' => $t['slug'], 'name' => $t['name'], 'short' => $t['short']])
            ->values()
            ->all();

        SeoService::trade($trade);
    }

    public function render()
    {
        return view('livewire.trade-page');
    }
}
