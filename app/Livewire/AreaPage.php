<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Services\SeoService;
use Artesaos\SEOTools\Facades\OpenGraph;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class AreaPage extends Component
{
    public AreaServed $area;

    public string $page = 'home';
    
    public ?string $service = null;

    public function mount(AreaServed $area, ?string $page = null, ?string $service = null): void
    {
        $this->area = $area;
        $this->page = $page ?? 'home';
        $this->service = $service;

        // Share area with all views (for navbar, footer, etc.)
        View::share('currentArea', $area);

        // Set SEO based on page type
        if ($this->page === 'service' && $this->service) {
            // Map URL slugs to internal service types
            $serviceMap = [
                'kitchens' => 'kitchen-remodeling',
                'bathrooms' => 'bathroom-remodeling',
                'home-remodeling' => 'home-remodeling',
            ];
            $serviceType = $serviceMap[$this->service] ?? abort(404);
            SeoService::areaService($area, $serviceType);
        } else {
            match ($this->page) {
                'contact' => SeoService::contact($area),
                'testimonials' => SeoService::testimonials($area),
                'projects' => SeoService::projects($area),
                'about' => SeoService::about($area),
                'services' => SeoService::services($area),
                default => SeoService::home($area),
            };
        }

        $path = request()->path();
        if (str_starts_with($path, 'locations/') || str_starts_with($path, 'areas/')) {
            $canonicalPath = preg_replace('#^(locations|areas)/#', 'areas-served/', $path);
            SEOMeta::setCanonical(url('/' . ltrim($canonicalPath, '/')));
            OpenGraph::setUrl(url('/' . ltrim($canonicalPath, '/')));
            SEOMeta::setRobots('noindex,follow');
        }
    }

    public function render()
    {
        return view('livewire.area-page', [
            'area' => $this->area,
            'page' => $this->page,
            'service' => $this->service,
        ]);
    }
}
