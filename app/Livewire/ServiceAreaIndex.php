<?php

namespace App\Livewire;

use App\Services\ZipCodeService;
use Artesaos\SEOTools\Facades\OpenGraph;
use Artesaos\SEOTools\Facades\SEOMeta;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ServiceAreaIndex extends Component
{
    /** @var array<string, array<int, string>> */
    public array $grouped = [];

    public int $totalZips = 0;

    public function mount(ZipCodeService $zips): void
    {
        $this->grouped = $zips->groupedByCity();
        $this->totalZips = array_sum(array_map('count', $this->grouped));

        $title = 'Service Area by ZIP Code | GS Construction';
        $description = 'GS Construction serves ' . $this->totalZips
            . ' ZIP codes across Chicagoland. Find your ZIP for kitchen, bathroom and home remodeling near you.';

        SEOMeta::setTitle($title);
        SEOMeta::setDescription($description);
        SEOMeta::setCanonical(url('/service-area'));
        OpenGraph::setTitle($title)->setDescription($description)->setUrl(url('/service-area'));
    }

    public function render()
    {
        return view('livewire.service-area-index');
    }
}
