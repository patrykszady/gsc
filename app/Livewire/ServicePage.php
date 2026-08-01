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

    /**
     * Service content for the CURRENT tenant.
     *
     * Was a 210-line hardcoded array in this class. Two things that fixed: the
     * "Our Process" block contradicted the six-stage process published on
     * /process, and hardcoding GS Construction's copy in shared code meant any
     * tenant claiming /services rendered a contractor's kitchen page. A site
     * with no services-content config now 404s instead.
     */
    protected function getServiceData(string $service): array
    {
        $services = (array) config('services-content.services', []);

        abort_unless(isset($services[$service]), 404);

        return $services[$service] + [
            // The company's real process, shared by every service so this page
            // and /process cannot drift apart.
            'process' => (array) config('services-content.process', []),
        ];
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
