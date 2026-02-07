<?php

namespace App\Livewire;

use App\Models\Project;
use App\Services\SeoService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ServicePage extends Component
{
    public string $service;

    public array $data = [];

    public function mount(string $service): void
    {
        $this->service = $service;
        $this->data = $this->getServiceData($service);

        // Set SEO
        SeoService::service($service);
    }

    protected function getServiceData(string $service): array
    {
        $services = [
            'kitchen-remodeling' => [
                'title' => 'Kitchen Remodeling',
                'heroTitle' => 'Kitchen Remodeling Contractors',
                'heroSubtitle' => 'Transform your kitchen with custom cabinets, countertops, and complete renovations',
                'projectType' => 'kitchen',
                'description' => 'Transform your kitchen into the heart of your home with GS Construction\'s expert kitchen remodeling services. From custom cabinetry and premium countertops to complete renovations, we create beautiful, functional spaces where families gather and memories are made.',
                'features' => [
                    ['title' => 'Custom Cabinetry', 'description' => 'Handcrafted cabinets designed to maximize storage and complement your style.'],
                    ['title' => 'Premium Countertops', 'description' => 'Granite, quartz, marble, and other premium materials expertly installed.'],
                    ['title' => 'Modern Appliances', 'description' => 'Integration of state-of-the-art appliances for the ultimate cooking experience.'],
                    ['title' => 'Lighting Design', 'description' => 'Task, ambient, and accent lighting to create the perfect atmosphere.'],
                ],
                'process' => [
                    ['step' => 1, 'title' => 'Consultation', 'description' => 'We discuss your vision, needs, and budget to create the perfect plan.'],
                    ['step' => 2, 'title' => 'Design', 'description' => 'Our designers create detailed 3D renderings of your new kitchen.'],
                    ['step' => 3, 'title' => 'Build', 'description' => 'Expert craftsmen bring your dream kitchen to life with precision.'],
                    ['step' => 4, 'title' => 'Enjoy', 'description' => 'We complete a final walkthrough and hand over your stunning new kitchen.'],
                ],
                'ctaHeading' => 'Ready to Transform Your Kitchen?',
                'faqs' => [
                    ['question' => 'How much does a kitchen remodel cost?', 'answer' => 'Every kitchen remodel is different — cost depends on the scope of work, materials you choose, and the size of your space. We provide free in-home estimates with a detailed breakdown tailored to your specific project and budget.'],
                    ['question' => 'How long does a kitchen remodel take?', 'answer' => 'The timeline depends on the scope of your project — layout changes, custom cabinetry, and material lead times all play a role. We create a detailed schedule before work begins and keep you informed throughout.'],
                    ['question' => 'Do you handle permits for kitchen remodeling?', 'answer' => 'Yes, GS Construction handles all necessary permits for kitchen remodeling projects. We are familiar with local building codes across the Chicagoland area and ensure your project is fully compliant.'],
                    ['question' => 'Can I stay in my home during a kitchen remodel?', 'answer' => 'Absolutely! Most of our clients stay in their homes during kitchen remodels. We set up temporary kitchen areas and minimize disruption to your daily routine.'],
                    ['question' => 'What is included in a full kitchen remodel?', 'answer' => 'A full kitchen remodel typically includes demolition, new cabinetry, countertops, flooring, backsplash, plumbing and electrical updates, lighting, and appliance installation. We customize every project to your needs and budget.'],
                ],
            ],
            'bathroom-remodeling' => [
                'title' => 'Bathroom Remodeling',
                'heroTitle' => 'Bathroom Remodeling Contractors',
                'heroSubtitle' => 'Create your personal spa retreat with luxury showers, tubs, and tile work',
                'projectType' => 'bathroom',
                'description' => 'Create your personal spa retreat with GS Construction\'s expert bathroom remodeling services. From luxurious walk-in showers and soaking tubs to modern vanities and custom tile work, we design bathrooms that combine comfort with style.',
                'features' => [
                    ['title' => 'Walk-In Showers', 'description' => 'Frameless glass, rain heads, and custom tile for a spa-like experience.'],
                    ['title' => 'Luxury Tubs', 'description' => 'Freestanding, jetted, and soaking tubs for ultimate relaxation.'],
                    ['title' => 'Custom Vanities', 'description' => 'Double sinks, ample storage, and stunning countertops.'],
                    ['title' => 'Heated Floors', 'description' => 'Radiant heating for comfort on cold mornings.'],
                ],
                'process' => [
                    ['step' => 1, 'title' => 'Consultation', 'description' => 'We assess your space and discuss your dream bathroom vision.'],
                    ['step' => 2, 'title' => 'Design', 'description' => 'Our team creates a detailed plan with material selections.'],
                    ['step' => 3, 'title' => 'Build', 'description' => 'Expert installation with attention to waterproofing and quality.'],
                    ['step' => 4, 'title' => 'Enjoy', 'description' => 'Your new bathroom retreat is ready for years of relaxation.'],
                ],
                'ctaHeading' => 'Ready to Create Your Dream Bathroom?',
                'faqs' => [
                    ['question' => 'How much does a bathroom remodel cost?', 'answer' => 'Bathroom remodeling costs vary based on the size of your space, finishes, and scope of work. We offer free in-home estimates tailored to your vision and budget.'],
                    ['question' => 'How long does a bathroom remodel take?', 'answer' => 'The timeline depends on the scope of your renovation — tile work, fixture changes, and any structural modifications all factor in. We provide a detailed schedule before starting any work.'],
                    ['question' => 'Do you install walk-in showers?', 'answer' => 'Yes! Walk-in showers are one of our most popular requests. We install frameless glass enclosures, custom tile, rain shower heads, and accessible curbless designs.'],
                    ['question' => 'Can you make my bathroom more accessible?', 'answer' => 'Absolutely. We specialize in accessibility modifications including grab bars, walk-in tubs, curbless showers, wider doorways, and comfort-height toilets for safe, comfortable living.'],
                    ['question' => 'Do you handle plumbing during bathroom remodels?', 'answer' => 'Yes, our team handles all plumbing work as part of the remodel, including moving fixtures, installing new supply lines, and updating drain systems to meet current codes.'],
                ],
            ],
            'home-remodeling' => [
                'title' => 'Home Remodeling',
                'heroTitle' => 'Home Remodeling Contractors',
                'heroSubtitle' => 'Complete home renovations, room additions, and open floor plans',
                'projectType' => 'home-remodel',
                'description' => 'Comprehensive home renovations that breathe new life into your entire living space. From room additions and open floor plans to complete home makeovers, GS Construction handles projects of any scale with precision and craftsmanship.',
                'features' => [
                    ['title' => 'Room Additions', 'description' => 'Expand your living space with seamlessly integrated additions.'],
                    ['title' => 'Open Floor Plans', 'description' => 'Remove walls and create flowing, modern living spaces.'],
                    ['title' => 'Whole-Home Renovations', 'description' => 'Complete transformations from foundation to roof.'],
                    ['title' => 'Aging-in-Place', 'description' => 'Accessibility modifications for comfortable living at any age.'],
                ],
                'process' => [
                    ['step' => 1, 'title' => 'Consultation', 'description' => 'We evaluate your home and discuss your complete vision.'],
                    ['step' => 2, 'title' => 'Design', 'description' => 'Architects and designers create comprehensive plans.'],
                    ['step' => 3, 'title' => 'Build', 'description' => 'Phased construction minimizes disruption to your daily life.'],
                    ['step' => 4, 'title' => 'Enjoy', 'description' => 'Move into your beautifully transformed home.'],
                ],
                'ctaHeading' => 'Ready to Transform Your Home?',
                'faqs' => [
                    ['question' => 'What does whole home remodeling include?', 'answer' => 'Whole home remodeling can include kitchen and bathroom renovations, open floor plan conversions, room additions, basement finishing, and complete interior updates. We customize every project to your needs and budget.'],
                    ['question' => 'How long does a whole home remodel take?', 'answer' => 'The timeline for a whole home remodel depends entirely on the scope — whether it includes structural changes, additions, or a full interior renovation. We create detailed project timelines and keep you updated throughout construction.'],
                    ['question' => 'Do you handle room additions?', 'answer' => 'Yes, we handle room additions including sunrooms, master suites, family rooms, and second-story additions. We manage everything from architectural design through final construction.'],
                    ['question' => 'Can you convert my home to an open floor plan?', 'answer' => 'Open floor plan conversions are one of our specialties! We safely remove walls, including load-bearing walls with proper engineering and permits, to create modern, flowing living spaces.'],
                    ['question' => 'Do you work with architects and designers?', 'answer' => 'Yes, we collaborate with architects and interior designers, and also have in-house design capabilities. Whether you bring your own plans or need us to design from scratch, we ensure your vision becomes reality.'],
                ],
            ],
            // 'basement-remodeling' => [
            //     'title' => 'Basement Remodeling',
            //     'heroTitle' => 'Basement Finishing & Remodeling',
            //     'heroSubtitle' => 'Unlock your basement\'s potential with expert finishing services',
            //     'projectType' => 'basement',
            //     'description' => 'Unlock your basement\'s potential with GS Construction\'s expert finishing and renovation services. Whether you envision a home theater, guest suite, home gym, or recreation room, we transform unused space into valuable living areas.',
            //     'features' => [
            //         ['title' => 'Home Theaters', 'description' => 'Soundproofed rooms with optimal acoustics and lighting.'],
            //         ['title' => 'Guest Suites', 'description' => 'Complete bedrooms with en-suite bathrooms.'],
            //         ['title' => 'Recreation Rooms', 'description' => 'Game rooms, bars, and entertainment spaces.'],
            //         ['title' => 'Home Offices', 'description' => 'Quiet, productive workspaces away from distractions.'],
            //     ],
            //     'process' => [
            //         ['step' => 1, 'title' => 'Consultation', 'description' => 'We assess your basement\'s potential and your goals.'],
            //         ['step' => 2, 'title' => 'Design', 'description' => 'Our team plans for moisture control, egress, and layout.'],
            //         ['step' => 3, 'title' => 'Build', 'description' => 'Expert finishing with proper insulation and systems.'],
            //         ['step' => 4, 'title' => 'Enjoy', 'description' => 'Your new living space is ready for years of enjoyment.'],
            //     ],
            //     'ctaHeading' => 'Ready to Finish Your Basement?',
            // ],
        ];

        return $services[$service] ?? abort(404);
    }

    public function render()
    {
        $projects = Project::query()
            ->published()
            ->ofType($this->data['projectType'])
            ->with(['images'])
            ->latest('completed_at')
            ->take(6)
            ->get();

        return view('livewire.service-page', [
            'projects' => $projects,
        ]);
    }
}
