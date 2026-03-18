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

    protected function getFaqs(): array
    {
        return [
            ['question' => 'What areas does GS Construction serve?', 'answer' => 'We serve over 89 cities across Chicagoland, including Arlington Heights, Palatine, Mount Prospect, Schaumburg, Buffalo Grove, Barrington, and communities throughout the Northwest Suburbs, North Shore, and greater Chicago area.'],
            ['question' => 'Do you charge extra for projects outside your main service area?', 'answer' => 'No, we do not charge extra travel fees for projects within our service area. If your city is listed on our areas served page, standard pricing applies.'],
            ['question' => 'How do I know if you serve my city?', 'answer' => 'Browse our areas served directory above. If you do not see your city listed, contact us anyway — we frequently take on projects in neighboring communities.'],
            ['question' => 'Can I see projects you have completed in my area?', 'answer' => 'Yes! Click on your city above to see our remodeling projects, reviews, and service information specific to your area.'],
        ];
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
            'faqs' => $this->getFaqs(),
        ]);
    }
}
