<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Testimonial;
use App\Services\SeoService;
use App\Support\SEO\AreaSeoPolicy;
use App\Support\SEO\SEOBuilder;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

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

        // An unknown service slug 404s instead of rendering a fallback page.
        if ($this->page === 'service' && $service !== null && ! $area->hasServicePage($service)) {
            abort(404);
        }

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
            SeoService::areaService($area, $this->service);
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

        // Keep thin, near-duplicate area spokes out of the index. Cities without
        // real local proof (a project or review) and pure nav variants
        // (contact/about/services) are noindexed so Google's quality budget
        // concentrates on the pages that can actually rank. See AreaSeoPolicy.
        $policyPage = $this->page === 'service' ? 'service' : $this->page;
        if (! AreaSeoPolicy::shouldIndex($area, $policyPage, $this->service)) {
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
