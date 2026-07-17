<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Testimonial;
use App\Services\ZipCodeService;
use App\Support\SEO\SEOBuilder;
use Illuminate\Support\Facades\Storage;
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

    public ?string $zipIntro = null;

    public ?string $zipLocalContext = null;

    public ?string $zipLandmarks = null;

    public ?string $zipPermitNotes = null;

    /** Completed-project count for THIS exact ZIP from the Hive PM system. */
    public int $hiveZipCount = 0;

    /** @var array<int,array{zip:string,count:int,miles:float}> nearest served ZIPs */
    public array $nearbyZips = [];

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
        $this->area = $info['area_slug']
            ? AreaServed::where('slug', $info['area_slug'])->first()
            : null;

        // Get all area slugs within 10 miles
        $nearbyAreaSlugs = $zips->getNearbyAreaSlugs($this->zip, 10.0);
        $nearbyAreas = AreaServed::whereIn('slug', $nearbyAreaSlugs)->get();
        $nearbyCityKeys = $nearbyAreas->map(fn($a) => mb_strtolower(trim($a->city)))->unique()->values()->all();

        // Aggregate project IDs from all nearby areas
        $allProjectIds = collect();
        foreach ($nearbyCityKeys as $cityKey) {
            $cityProjects = Project::query()
                ->where('is_published', true)
                ->whereRaw('LOWER(TRIM(SUBSTRING_INDEX(location, ",", 1))) = ?', [$cityKey])
                ->pluck('id');
            $allProjectIds = $allProjectIds->merge($cityProjects);
        }
        $allProjectIds = $allProjectIds->unique()->values();

        // Count projects + testimonials from nearby areas
        $projectCount = $allProjectIds->count();
        $testimonialCount = Testimonial::query()
            ->where('is_hidden', false)
            ->where(function ($q) use ($nearbyCityKeys) {
                foreach ($nearbyCityKeys as $cityKey) {
                    $q->orWhereRaw('LOWER(TRIM(SUBSTRING_INDEX(project_location, ",", 1))) = ?', [$cityKey]);
                }
            })
            ->count();
        $this->projectCount = $projectCount + $testimonialCount;
        $this->projects = Project::query()
            ->whereIn('id', $allProjectIds)
            ->where('is_published', true)
            ->with('images')
            ->orderByDesc('updated_at')
            ->limit(12)
            ->get();

        // Exact completed-jobs count for this ZIP from Hive — the strongest
        // unique fact a ZIP page can state (crawlable text, not just map bubbles).
        $zipPoints = collect(app(\App\Services\HiveProjectsClient::class)->storedZipPoints());
        $self = $zipPoints->firstWhere('zip', $this->zip);
        $this->hiveZipCount = (int) ($self['count'] ?? 0);

        // Nearest served ZIPs by haversine, with their own project counts —
        // unique per-page internal mesh between ZIP pages.
        $lat = (float) ($self['lat'] ?? $this->area?->latitude ?? 0);
        $lng = (float) ($self['lng'] ?? $this->area?->longitude ?? 0);
        if ($lat !== 0.0) {
            $this->nearbyZips = $zipPoints
                ->reject(fn ($p) => $p['zip'] === $this->zip)
                ->map(function ($p) use ($lat, $lng) {
                    $dLat = deg2rad($p['lat'] - $lat);
                    $dLng = deg2rad($p['lng'] - $lng);
                    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($p['lat'])) * sin($dLng / 2) ** 2;
                    $p['miles'] = 3959 * 2 * atan2(sqrt($a), sqrt(1 - $a));

                    return $p;
                })
                ->sortBy('miles')
                ->take(8)
                ->values()
                ->all();
        }

        $zipContent = $this->loadZipContent($this->zip);
        if ($zipContent) {
            $this->zipIntro = $zipContent['intro'] ?? null;
            $this->zipLocalContext = $zipContent['local_context'] ?? null;
            $this->zipLandmarks = $zipContent['landmarks'] ?? null;
            $this->zipPermitNotes = $zipContent['permit_notes'] ?? null;
        }

        $title = "Home Remodeling near {$this->city}, IL {$this->zip} | GS Construction";
        $description = $this->zipIntro
            ?: ("Kitchen, bathroom and whole-home remodeling serving {$this->city}, IL ZIP code {$this->zip}. "
                . "Free in-home estimates from a family-owned, licensed contractor. "
                . ($this->projectCount > 0 ? "{$this->projectCount} completed projects nearby." : ''));

        $title = \Illuminate\Support\Str::limit($title, 60, '');
        $description = \Illuminate\Support\Str::limit(trim((string) preg_replace('/\s+/u', ' ', $description)), 160, '');

        app(SEOBuilder::class)
            ->title($title)
            ->description($description)
            ->canonical(url('/service-area/' . $this->zip))
            ->url(url('/service-area/' . $this->zip))
            ->type('website')
            ->keywords([
                "remodeling {$this->zip}",
                "kitchen remodel {$this->city}",
                "bathroom remodel {$this->city}",
                "general contractor {$this->city} IL",
            ]);
        // LocalBusiness JSON-LD is emitted inline by livewire.zip-code-page view.
    }

    public function render()
    {
        return view('livewire.zip-code-page');
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function loadZipContent(string $zip): ?array
    {
        $path = 'seo/zip-content.json';
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) Storage::disk('local')->get($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        $zipData = $decoded[$zip] ?? null;
        return is_array($zipData) ? $zipData : null;
    }
}
