<?php

namespace App\Livewire;

use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\SeoService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ServicesPage extends Component
{
    public function mount(): void
    {
        SeoService::services();
    }


    protected function getFallbackForType(string $type): string
    {
        return match ($type) {
            'kitchen' => 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=1920&q=80',
            'bathroom' => 'https://images.unsplash.com/photo-1552321554-5fefe8c9ef14?w=1920&q=80',
            'home-remodel' => 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80',
            'basement' => 'https://images.unsplash.com/photo-1505691938895-1758d7feb511?w=1920&q=80',
            'addition' => 'https://images.unsplash.com/photo-1572120360610-d971b9d7767c?w=1920&q=80',
            default => 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80',
        };
    }

    /**
     * Slug + title for every service, for the schema loops in the view.
     *
     * Was a 90-line hand-maintained array duplicating config — descriptions,
     * gradients and feature bullets already owned by services-content 'grid'
     * (rendered via partials/services-grid) — kept alive only because the
     * ItemList/Product schema needed slugs and titles. Those now come from
     * ServiceCatalog, so adding a service in config updates this page's grid,
     * schema and cards together.
     */
    public function getServicesProperty(): array
    {
        return \App\Support\ServiceCatalog::all()
            ->map(fn (array $s) => ['slug' => $s['slug'], 'title' => $s['label']])
            ->values()
            ->all();
    }

    protected function getFaqs(): array
    {
        return [
            ['question' => 'What remodeling services does GS Construction offer?', 'answer' => 'We specialize in kitchen remodeling, bathroom remodeling, whole-home renovations, basement finishing, and home additions. As a licensed general contractor, we handle cabinetry, countertops, tile work, flooring, plumbing, electrical, structural modifications, room additions, and more.'],
            ['question' => 'Are you a licensed general contractor?', 'answer' => 'Yes — GS Construction is a fully licensed and insured general contractor based in the Chicago suburbs. We hold local municipal licenses, carry general liability and workers\' comp insurance, and self-perform most trades with our in-house crew.'],
            ['question' => 'Do you offer free consultations?', 'answer' => 'Yes! We provide free in-home consultations where we assess your space, discuss your vision, and provide a detailed, no-obligation estimate. Call us at (224) 735-4200 to schedule.'],
            ['question' => 'What areas do you serve?', 'answer' => 'We serve ' . \App\Support\CompanyStats::citiesServedLabel() . ' cities across Chicagoland, including Arlington Heights, Palatine, Mount Prospect, Schaumburg, Buffalo Grove, Barrington, and communities throughout the Northwest Suburbs, North Shore, and greater Chicago area.'],
            ['question' => 'How experienced is your team?', 'answer' => 'GS Construction is a family-owned general contracting business with over 40 years of combined experience. Greg and Patryk bring expertise in all aspects of residential remodeling, backed by ' . \App\Support\CompanyStats::reviewsCountLabel() . ' five-star Google reviews.'],
            ['question' => 'Do you handle the entire project from start to finish?', 'answer' => 'Yes, we are a full-service general contractor. From initial design and permits to construction and final inspection, we manage every aspect of your project so you have a single point of contact throughout.'],
        ];
    }

    public function render()
    {
        return view('livewire.services-page', [
            'faqs' => $this->getFaqs(),
        ]);
    }
}
