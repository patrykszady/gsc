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

        // Publish only what a card renders.
        //
        // This held the whole config entry, and Livewire serialises public
        // properties into the wire:snapshot embedded in the page — so every
        // `them` claim, every verbatim source quote and every source URL for
        // all 26 competitors was readable in this page's HTML with nothing
        // rendering it. The per-competitor pages were fixed for exactly this;
        // the hub was missed.
        $prompts = (array) config('competitors.card_prompts', []);
        shuffle($prompts);

        $this->competitors = [];
        foreach (array_values($competitors) as $i => $competitor) {
            $this->competitors[] = [
                'slug' => (string) ($competitor['slug'] ?? ''),
                'name' => (string) ($competitor['name'] ?? ''),
                // Dealt round-robin from an already-shuffled pool: neighbouring
                // cards never match, and the wording rotates on every reload.
                'prompt' => $prompts === []
                    ? 'See what we offer'
                    : $prompts[$i % count($prompts)],
            ];
        }

        SeoService::compareIndex();
    }

    public function render()
    {
        return view('livewire.compare-index-page');
    }
}
