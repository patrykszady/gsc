<?php

namespace App\Livewire;

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

    protected function getCoverImageForType(string $projectType): string
    {
        $image = ProjectImage::query()
            ->where('is_cover', true)
            ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
            ->inRandomOrder()
            ->first();

        return $image?->url ?? $this->getFallbackForType($projectType);
    }

    protected function getFallbackForType(string $type): string
    {
        return match ($type) {
            'kitchen' => 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=1920&q=80',
            'bathroom' => 'https://images.unsplash.com/photo-1552321554-5fefe8c9ef14?w=1920&q=80',
            'home-remodel' => 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80',
            default => 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80',
        };
    }

    public function getServicesProperty(): array
    {
        return [
            [
                'slug' => 'kitchen-remodeling',
                'title' => 'Kitchen Remodeling',
                'projectType' => 'kitchen',
                'description' => 'Transform your kitchen into the heart of your home. From custom cabinetry and premium countertops to complete renovations – we create beautiful, functional spaces where families gather and memories are made.',
                'image' => $this->getCoverImageForType('kitchen'),
                'gradient' => 'from-sky-500 to-blue-600',
                'features' => [
                    'Custom cabinetry & storage solutions',
                    'Granite, quartz & marble countertops',
                    'Flooring, lighting & complete renovations',
                ],
            ],
            [
                'slug' => 'bathroom-remodeling',
                'title' => 'Bathroom Remodeling',
                'projectType' => 'bathroom',
                'description' => 'Create your personal spa retreat with expert bathroom renovations. From luxurious walk-in showers and soaking tubs to modern vanities and tile work – we design bathrooms that combine comfort with style.',
                'image' => $this->getCoverImageForType('bathroom'),
                'gradient' => 'from-indigo-500 to-purple-600',
                'features' => [
                    'Walk-in showers & luxury tubs',
                    'Custom tile work & vanities',
                    'Modern fixtures & lighting',
                ],
            ],
            [
                'slug' => 'home-remodeling',
                'title' => 'Home Remodeling',
                'projectType' => 'home-remodel',
                'description' => 'Comprehensive home renovations that breathe new life into your entire living space. From room additions and open floor plans to complete home makeovers – we handle projects of any scale with precision.',
                'image' => $this->getCoverImageForType('home-remodel'),
                'gradient' => 'from-emerald-500 to-teal-600',
                'features' => [
                    'Room additions & expansions',
                    'Open concept floor plans',
                    'Complete home renovations',
                ],
            ],
        ];
    }

    protected function getFaqs(): array
    {
        return [
            ['question' => 'What remodeling services does GS Construction offer?', 'answer' => 'We specialize in three core areas: kitchen remodeling, bathroom remodeling, and whole-home renovations. This includes cabinetry, countertops, tile work, flooring, plumbing, electrical, structural modifications, room additions, and more.'],
            ['question' => 'Do you offer free consultations?', 'answer' => 'Yes! We provide free in-home consultations where we assess your space, discuss your vision, and provide a detailed, no-obligation estimate. Call us at (224) 735-4200 to schedule.'],
            ['question' => 'What areas do you serve?', 'answer' => 'We serve over 89 cities across Chicagoland, including Arlington Heights, Palatine, Mount Prospect, Schaumburg, Buffalo Grove, Barrington, and communities throughout the Northwest Suburbs, North Shore, and greater Chicago area.'],
            ['question' => 'How experienced is your team?', 'answer' => 'GS Construction is a family-owned business with over 40 years of combined experience. Greg and Patryk bring expertise in all aspects of residential remodeling, backed by 53+ five-star Google reviews.'],
            ['question' => 'Do you handle the entire project from start to finish?', 'answer' => 'Yes, we are a full-service remodeling company. From initial design and permits to construction and final inspection, we manage every aspect of your project so you have a single point of contact throughout.'],
        ];
    }

    public function render()
    {
        return view('livewire.services-page', [
            'faqs' => $this->getFaqs(),
        ]);
    }
}
