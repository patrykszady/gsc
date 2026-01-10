<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Services\SeoService;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class AreaPage extends Component
{
    public AreaServed $area;

    public string $page = 'home';

    public function mount(AreaServed $area, ?string $page = null): void
    {
        $this->area = $area;
        $this->page = $page ?? 'home';

        // Share area with all views (for navbar, footer, etc.)
        View::share('currentArea', $area);

        // Set SEO based on page type
        match ($this->page) {
            'contact' => SeoService::contact($area),
            'testimonials' => SeoService::testimonials($area),
            'projects' => SeoService::projects($area),
            'about' => SeoService::about($area),
            'services' => SeoService::services($area),
            'kitchen-remodeling' => SeoService::areaService($area, 'kitchen-remodeling'),
            'bathroom-remodeling' => SeoService::areaService($area, 'bathroom-remodeling'),
            'home-remodeling' => SeoService::areaService($area, 'home-remodeling'),
            default => SeoService::home($area),
        };
    }

    public function render()
    {
        return view('livewire.area-page', [
            'area' => $this->area,
            'page' => $this->page,
        ]);
    }
}
