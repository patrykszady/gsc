<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\Project;
use App\Services\ZipCodeService;
use Artesaos\SEOTools\Facades\JsonLd;
use Artesaos\SEOTools\Facades\OpenGraph;
use Artesaos\SEOTools\Facades\SEOMeta;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ZipCodePage extends Component
{
    public string $zip = '';

    public string $city = '';

    public ?AreaServed $area = null;

    /** @var \Illuminate\Support\Collection<int, Project> */
    public $projects;

    public int $projectCount = 0;

    public function mount(string $zip, ZipCodeService $zips): void
    {
        $zip = preg_replace('/\D/', '', $zip);
        if (strlen((string) $zip) !== 5) {
            abort(404);
        }

        $info = $zips->find($zip);
        if (! $info) {
            abort(404);
        }

        $this->zip = (string) $zip;
        $this->city = $info['city'];
        $this->projectCount = $info['count'];
        $this->area = $info['area_slug']
            ? AreaServed::where('slug', $info['area_slug'])->first()
            : null;

        $this->projects = Project::query()
            ->whereIn('id', $info['project_ids'])
            ->where('is_published', true)
            ->with('images')
            ->orderByDesc('updated_at')
            ->limit(12)
            ->get();

        $title = "Home Remodeling near {$this->city}, IL {$this->zip} | GS Construction";
        $description = "Kitchen, bathroom and whole-home remodeling serving {$this->city}, IL ZIP code {$this->zip}. "
            . "Free in-home estimates from a family-owned, licensed contractor. "
            . ($this->projectCount > 0 ? "{$this->projectCount} completed projects nearby." : '');

        SEOMeta::setTitle($title);
        SEOMeta::setDescription($description);
        SEOMeta::setCanonical(url('/service-area/' . $this->zip));
        SEOMeta::addKeyword([
            "remodeling {$this->zip}",
            "kitchen remodel {$this->city}",
            "bathroom remodel {$this->city}",
            "general contractor {$this->city} IL",
        ]);
        OpenGraph::setTitle($title)
            ->setDescription($description)
            ->setUrl(url('/service-area/' . $this->zip))
            ->setType('website');

        JsonLd::setTitle($title)
            ->setDescription($description)
            ->setUrl(url('/service-area/' . $this->zip))
            ->setType('LocalBusiness');
    }

    public function render()
    {
        return view('livewire.zip-code-page');
    }
}
