<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\Project;
use App\Services\SeoService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ProjectPage extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        // Only show published projects
        if (!$project->is_published) {
            abort(404);
        }

        $this->project = $project->load(['images', 'timelapses.frames', 'beforeAfters']);
        
        // Sort images: featured (is_cover) first, then randomize the rest
        $this->project->setRelation('images', 
            $this->project->images
                ->sortByDesc('is_cover')
                ->groupBy('is_cover')
                ->flatMap(fn($group, $key) => $key ? $group : $group->shuffle())
        );

        SeoService::project($project);
    }

    protected function getProjectTypeLabel(): string
    {
        $types = Project::projectTypes();
        return $types[$this->project->project_type] ?? ucfirst(str_replace('-', ' ', $this->project->project_type));
    }

    protected function getRelatedProjects()
    {
        return Project::query()
            ->published()
            ->where('id', '!=', $this->project->id)
            ->where('project_type', $this->project->project_type)
            ->with('images')
            ->take(3)
            ->get();
    }

    protected function getLocationArea(): ?AreaServed
    {
        if (!$this->project->location) {
            return null;
        }

        // Extract city name from location (e.g., "Arlington Heights, IL" -> "Arlington Heights")
        $city = preg_replace('/,?\s*(IL|Illinois)$/i', '', $this->project->location);
        $city = trim($city);

        return AreaServed::where('city', $city)->first();
    }

    protected function getFaqs(): array
    {
        $type = $this->project->project_type;

        $faqs = match ($type) {
            'kitchen' => [
                ['question' => 'How much does a kitchen remodel cost?', 'answer' => 'Every kitchen remodel is different — cost depends on the scope of work, materials you choose, and the size of your space. We provide free in-home estimates with a detailed breakdown tailored to your specific project and budget.'],
                ['question' => 'How long does a kitchen remodel take?', 'answer' => 'The timeline depends on the scope of your project — layout changes, custom cabinetry, and material lead times all play a role. We create a detailed schedule before work begins and keep you informed throughout.'],
                ['question' => 'Do you handle permits for kitchen remodeling?', 'answer' => 'Yes, GS Construction handles all necessary permits for kitchen remodeling projects. We are familiar with local building codes across the Chicagoland area and ensure your project is fully compliant.'],
                ['question' => 'Can I stay in my home during a kitchen remodel?', 'answer' => 'Absolutely! Most of our clients stay in their homes during kitchen remodels. We set up temporary kitchen areas and minimize disruption to your daily routine.'],
            ],
            'bathroom' => [
                ['question' => 'How much does a bathroom remodel cost?', 'answer' => 'Bathroom remodel costs vary depending on the size of your bathroom, materials, and scope of work. We provide free, no-obligation estimates tailored to your specific project needs and budget.'],
                ['question' => 'How long does a bathroom remodel take?', 'answer' => 'Most bathroom remodels take between 2–6 weeks depending on the scope. We provide a detailed timeline before work begins and keep you updated at every stage.'],
                ['question' => 'Can you convert a tub to a walk-in shower?', 'answer' => 'Yes! Tub-to-shower conversions are one of our most popular bathroom upgrades. We design custom walk-in showers with frameless glass, rain heads, and premium tile work.'],
                ['question' => 'Do you offer accessible bathroom designs?', 'answer' => 'Absolutely. We design ADA-compliant and aging-in-place bathrooms with barrier-free showers, grab bars, comfort-height vanities, and non-slip flooring.'],
            ],
            'home-remodel' => [
                ['question' => 'What does a whole-home remodel include?', 'answer' => 'A whole-home remodel can include kitchen and bathroom renovations, room additions, open floor plan conversions, flooring, lighting, and structural modifications — all customized to your vision.'],
                ['question' => 'How long does a home remodeling project take?', 'answer' => 'Timelines vary by scope — a single room might take a few weeks, while a whole-home renovation can take several months. We provide a detailed schedule before work begins.'],
                ['question' => 'Can you add a room addition to my home?', 'answer' => 'Yes, we handle all types of room additions including bedrooms, family rooms, sunrooms, and second-story additions. We manage everything from design and permits to construction.'],
                ['question' => 'Do I need to move out during a home remodel?', 'answer' => 'In most cases, no. We phase the work to minimize disruption so you can continue living comfortably in your home. For very large-scale renovations, we will discuss the best approach with you.'],
            ],
            default => [
                ['question' => 'How do I get started with a remodeling project?', 'answer' => 'Contact us for a free in-home consultation. We will discuss your vision, assess your space, and provide a detailed estimate — no obligation.'],
                ['question' => 'How long does a typical remodeling project take?', 'answer' => 'Timelines depend on the scope of work. Smaller projects may take a few weeks, while larger renovations can span several months. We provide a detailed schedule before work begins.'],
                ['question' => 'Do you handle permits and inspections?', 'answer' => 'Yes, GS Construction handles all required permits and coordinates inspections. We are familiar with building codes across Chicagoland and ensure full compliance.'],
                ['question' => 'Are you licensed, bonded, and insured?', 'answer' => 'Yes, GS Construction is fully licensed, bonded, and insured. We carry general liability insurance and workers\' compensation coverage for your protection.'],
            ],
        };

        return $faqs;
    }

    public function render()
    {
        return view('livewire.project-page', [
            'projectTypeLabel' => $this->getProjectTypeLabel(),
            'relatedProjects' => $this->getRelatedProjects(),
            'locationArea' => $this->getLocationArea(),
            'faqs' => $this->getFaqs(),
        ]);
    }
}
