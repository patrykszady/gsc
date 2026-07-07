<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Services\SeoService;
use App\Support\SEO\SEOBuilder;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

use App\Models\Project;
use App\Models\Testimonial;
#[Layout('components.layouts.app')]
class AreaPage extends Component
{
    public AreaServed $area;

    public string $page = 'home';
    
    public ?string $service = null;

    public int $projectCount = 0;
    public function mount(AreaServed $area, ?string $page = null, ?string $service = null): void
    {
        $this->area = $area;
        $this->page = $page ?? 'home';
        $this->service = $service;

        // Calculate project count for this area (same logic as ZIP code page)
        $city = trim((string) $area->city);
        if ($city !== '') {
            $needle = mb_strtolower($city);
            
            // Count projects in this city
            $projectCount = Project::query()
                ->where('is_published', true)
                ->whereNotNull('location')
                ->where('location', '!=', '')
                ->get()
                ->filter(function (Project $project) use ($needle): bool {
                    $parts = preg_split('/[,.]/', (string) $project->location) ?: [];
                    $token = mb_strtolower(trim((string) ($parts[0] ?? '')));
                    return $token === $needle;
                })
                ->count();
            
            // Count testimonials in this city
            $testimonialCount = Testimonial::query()
                ->where('is_hidden', false)
                ->get()
                ->filter(function (Testimonial $t) use ($needle): bool {
                    $parts = preg_split('/[,.]/', (string) $t->project_location) ?: [];
                    $token = mb_strtolower(trim((string) ($parts[0] ?? '')));
                    return $token === $needle;
                })
                ->count();
            
            $this->projectCount = $projectCount + $testimonialCount;
        }
        // Share area with all views (for navbar, footer, etc.)
        View::share('currentArea', $area);

        // Set SEO based on page type
        if ($this->page === 'service' && $this->service) {
            // Whitelist the supported service spokes (mirrors the route's
            // ->where() constraint). An unknown slug 404s instead of rendering
            // a fallback page.
            $serviceMap = [
                'kitchen-remodeling' => 'kitchen-remodeling',
                'bathroom-remodeling' => 'bathroom-remodeling',
                'home-remodeling' => 'home-remodeling',
                'basement-remodeling' => 'basement-remodeling',
                'home-additions' => 'home-additions',
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
            app(SEOBuilder::class)
                ->canonical(url('/' . ltrim($canonicalPath, '/')))
                ->url(url('/' . ltrim($canonicalPath, '/')))
                ->markNoindex();
        }

        // Keep thin, near-duplicate area spokes out of the index. Cities without
        // real local proof (a project or review) and pure nav variants
        // (contact/about/services) are noindexed so Google's quality budget
        // concentrates on the pages that can actually rank. See AreaSeoPolicy.
        $policyPage = $this->page === 'service' ? 'service' : $this->page;
        if (! \App\Support\SEO\AreaSeoPolicy::shouldIndex($area, $policyPage, $this->service)) {
            app(SEOBuilder::class)->markNoindex();
        }
    }

    public function render()
    {
        return view('livewire.area-page', [
            'area' => $this->area,
            'page' => $this->page,
            'service' => $this->service,
                    'projectCount' => $this->projectCount,
        ]);
    }
}
