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
        if (! $this->area) {
            return $link;
        }

        // Map standard hrefs to area-specific pages
        $areaPageMap = [
            '/contact' => 'contact',
            '/projects' => 'projects',
            '/reviews' => 'testimonials',
            '/testimonials' => 'testimonials',
            '/about' => 'about',
            '/services' => 'services',
        ];

        // A service link becomes the town's own service page when the town
        // has one (every service on the admin's Services screen —
        // AreaServed::hasServicePage()); otherwise it stays site-wide.
        $serviceSlug = str_starts_with((string) $link['href'], '/services/')
            ? trim(substr((string) $link['href'], strlen('/services/')), '/')
            : '';

        if (isset($areaPageMap[$link['href']])) {
            $link['href'] = $this->area->pageUrl($areaPageMap[$link['href']]);
        } elseif ($serviceSlug !== '' && $this->area->hasServicePage($serviceSlug)) {
            $link['href'] = $this->area->serviceUrl($serviceSlug);
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
