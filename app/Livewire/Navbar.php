<?php

namespace App\Livewire;

use App\Models\AreaServed;
use Livewire\Component;

class Navbar extends Component
{
    public ?AreaServed $area = null;

    public function mount(): void
    {
        // Get area from shared view data if available
        $this->area = view()->shared('currentArea');
    }

    protected function transformLink(array $link): array
    {
        if (!$this->area) {
            return $link;
        }

        // Map standard hrefs to area-specific pages
        $areaPageMap = [
            '/contact' => 'contact',
            '/projects' => 'projects',
            '/testimonials' => 'testimonials',
            '/about' => 'about',
            '/services' => 'services',
            '/services/kitchen-remodeling' => 'services',
            '/services/bathroom-remodeling' => 'services',
            '/services/home-remodeling' => 'services',
        ];

        if (isset($areaPageMap[$link['href']])) {
            $link['href'] = $this->area->pageUrl($areaPageMap[$link['href']]);
        }

        return $link;
    }

    public function render()
    {
        $navLinks = collect(config('nav.links'))
            ->map(fn ($link) => $this->transformLink($link))
            ->toArray();

        return view('livewire.navbar', [
            'navLinks' => $navLinks,
            'area' => $this->area,
        ]);
    }
}
