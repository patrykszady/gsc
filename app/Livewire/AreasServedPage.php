<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Services\SeoService;
use Artesaos\SEOTools\Facades\OpenGraph;
use Artesaos\SEOTools\Facades\SEOMeta;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class AreasServedPage extends Component
{
    public function mount(): void
    {
        SeoService::areasServed();

        if (in_array(request()->path(), ['locations', 'areas'], true)) {
            SEOMeta::setCanonical(url('/areas-served'));
            OpenGraph::setUrl(url('/areas-served'));
            SEOMeta::setRobots('noindex,follow');
        }
    }

    public function render()
    {
        $areas = AreaServed::query()
            ->orderBy('city')
            ->get()
            ->groupBy(fn ($area) => strtoupper(substr($area->city, 0, 1)));

        return view('livewire.areas-served-page', [
            'groupedAreas' => $areas,
            'currentRoute' => request()->path(),
        ]);
    }
}
