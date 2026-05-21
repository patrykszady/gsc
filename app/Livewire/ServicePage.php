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
            'basement-remodeling' => [
                'title' => 'Basement Remodeling',
                'heroTitle' => 'Basement Finishing & Remodeling Contractors',
                'heroSubtitle' => 'Unlock your basement\'s potential — home theaters, guest suites, rec rooms & wet bars',
                'projectType' => 'basement',
                'description' => 'Unlock your basement\'s full potential with GS Construction\'s expert basement finishing and remodeling services. Whether you envision a home theater, in-law suite, home gym, or recreation room, we transform unused below-grade space into valuable, code-compliant living areas — including framing, electrical, plumbing, egress windows, drywall, flooring, and finishes.',
                'features' => [
                    ['title' => 'Home Theaters & Media Rooms', 'description' => 'Soundproofed rooms with optimal acoustics, dimmable lighting, and built-in seating.'],
                    ['title' => 'Guest Suites & In-Law Apartments', 'description' => 'Bedrooms with egress windows, full en-suite bathrooms, and kitchenettes.'],
                    ['title' => 'Recreation Rooms & Wet Bars', 'description' => 'Game rooms, custom wet bars, and entertainment spaces built for hosting.'],
                    ['title' => 'Waterproofing & Egress', 'description' => 'Moisture control, sump systems, and code-compliant egress windows for bedrooms.'],
                ],
                'process' => [
                    ['step' => 1, 'title' => 'Consultation', 'description' => 'We inspect your basement, check for moisture issues, and plan your layout.'],
                    ['step' => 2, 'title' => 'Design & Permits', 'description' => 'Our team plans for moisture control, egress, electrical, and pulls permits.'],
                    ['step' => 3, 'title' => 'Build', 'description' => 'Framing, insulation, mechanicals, drywall, flooring, and finishes — all in-house.'],
                    ['step' => 4, 'title' => 'Enjoy', 'description' => 'Your new finished basement is ready for years of enjoyment.'],
                ],
                'ctaHeading' => 'Ready to Finish Your Basement?',
                'faqs' => [
                    ['question' => 'How much does basement finishing cost?', 'answer' => 'Finishing a basement in the Chicago suburbs typically runs $25,000–$50,000+ depending on square footage, layout, finishes, and whether you need a bathroom, wet bar, or egress windows. GS Construction provides a free, itemized estimate broken down by phase.'],
                    ['question' => 'How long does basement finishing take?', 'answer' => 'A typical basement finish takes 6–12 weeks. Waterproofing, framing, electrical, and plumbing are the longest phases. Adding a full bathroom or wet bar adds 1–2 weeks. We work to minimize disruption to your daily life.'],
                    ['question' => 'Do I need permits to finish my basement in Illinois?', 'answer' => 'Yes — Illinois municipalities (Arlington Heights, Palatine, Hoffman Estates, Schaumburg, etc.) all require building, electrical, and plumbing permits for basement finishing. GS Construction handles all permitting and inspections for you.'],
                    ['question' => 'Can you add a bedroom or bathroom in my basement?', 'answer' => 'Yes. Basement bedrooms require code-compliant egress windows, and bathrooms require proper plumbing tie-ins (often with an ejector pit). We handle the engineering, permits, and full build.'],
                    ['question' => 'What about moisture and water in the basement?', 'answer' => 'Before any finishing, we assess your basement for moisture intrusion. We can install vapor barriers, sump systems, drain tile, and proper insulation to ensure your finished space stays dry and mold-free.'],
                ],
            ],
            'home-additions' => [
                'title' => 'Home Additions',
                'heroTitle' => 'Home Addition Contractors',
                'heroSubtitle' => 'Room additions, master suite additions, sunrooms, and second-story expansions',
                'projectType' => 'addition',
                'description' => 'Expand your home with GS Construction\'s design-build home addition services. From single-room bump-outs and sunrooms to master suite additions and full second-story expansions, we design, permit, and build seamless additions that match your existing home\'s architecture inside and out.',
                'features' => [
                    ['title' => 'Room Additions & Bump-Outs', 'description' => 'Add square footage to family rooms, kitchens, or dining rooms.'],
                    ['title' => 'Master Suite Additions', 'description' => 'New primary bedrooms with walk-in closets and en-suite baths.'],
                    ['title' => 'Sunrooms & Four-Season Rooms', 'description' => 'Year-round living spaces with insulated walls, HVAC, and large windows.'],
                    ['title' => 'Second-Story Additions', 'description' => 'Full second-floor build-ups when expanding outward isn\'t an option.'],
                ],
                'process' => [
                    ['step' => 1, 'title' => 'Site Assessment', 'description' => 'We evaluate setbacks, lot size, structural capacity, and your goals.'],
                    ['step' => 2, 'title' => 'Design & Permits', 'description' => 'Architectural plans, engineering, and zoning/permit approvals.'],
                    ['step' => 3, 'title' => 'Build', 'description' => 'Foundation, framing, roofing, MEP, interior finishes — managed end-to-end.'],
                    ['step' => 4, 'title' => 'Enjoy', 'description' => 'Move into your seamlessly integrated, fully permitted addition.'],
                ],
                'ctaHeading' => 'Ready to Add On to Your Home?',
                'faqs' => [
                    ['question' => 'How much does a home addition cost?', 'answer' => 'A room addition or home extension typically costs $100–$300+ per square foot depending on the type of room, finishes, foundation work, and site conditions. A basic 400 sq ft addition might run $40,000–$80,000; a master suite addition often runs $80,000–$150,000+. GS Construction provides free, detailed estimates.'],
                    ['question' => 'How long does a room addition take?', 'answer' => 'Most room additions take 8–16 weeks depending on size, permits, weather, and structural work required. Foundation, framing, and roofing are the longest phases. Second-story additions typically take 16–24 weeks.'],
                    ['question' => 'Do you handle architectural plans and permits for additions?', 'answer' => 'Yes. We work with licensed architects and structural engineers and handle all village/city zoning, building, electrical, plumbing, and mechanical permits — including Arlington Heights, Palatine, Hoffman Estates, and Schaumburg.'],
                    ['question' => 'Can I add a second story to my existing house?', 'answer' => 'Often, yes. We evaluate the existing foundation and structural framing for capacity, work with an engineer to design proper reinforcement, and coordinate permits. Second-story additions are major projects but add significant square footage without losing yard space.'],
                    ['question' => 'Will the addition match my existing home?', 'answer' => 'Absolutely. Our design team specs matching siding, brick, roofing, windows, and trim so the addition reads as part of the original home. Interior transitions are also planned to flow naturally with existing finishes.'],
                ],
            ],
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
