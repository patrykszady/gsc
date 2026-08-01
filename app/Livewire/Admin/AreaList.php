<?php

namespace App\Livewire\Admin;

use App\Models\AreaServed;
use App\Services\OpenStreetMapGeocoder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class AreaList extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public ?string $mapFlash = null;

    public function delete(int $id): void
    {
        AreaServed::whereKey($id)->delete();
        $this->dispatch('area-deleted');
        $this->dispatch('areas-map-updated', areas: $this->mapAreas());
    }

    /**
     * States this tenant serves, for validating map adds.
     *
     * Derived from the site's markets when it declares them
     * (config/sites/{slug}/markets.php — jpeterson spans IL, GA and MI), and
     * from the GBP geocoding default otherwise (gsc: IL). Nothing hardcoded:
     * the old client-side check pinned Illinois for every tenant.
     *
     * @return array<int, string>
     */
    public function allowedStates(): array
    {
        $states = array_values(array_unique(array_filter(
            array_column((array) config('markets.list', []), 'state'),
        )));

        return $states !== []
            ? $states
            : [(string) config('services.google.business_profile.geocode_state', 'IL')];
    }

    /**
     * What town is under this map click? Server-side Nominatim, replacing the
     * browser Google Geocoder — that call is referer-restricted and dies with
     * REQUEST_DENIED on every *.localhost and preview host.
     *
     * @return array{city: string|null, state: string|null, allowed: bool, allowedStates: array<int, string>}
     */
    public function resolveTown(float $lat, float $lng): array
    {
        $town = app(OpenStreetMapGeocoder::class)->reverseTown($lat, $lng);
        $allowed = $this->allowedStates();

        return $town + [
            'allowed' => $town['state'] !== null && in_array($town['state'], $allowed, true),
            'allowedStates' => $allowed,
        ];
    }

    /**
     * Named towns in the current viewport that are NOT yet service areas —
     * the orange candidate dots.
     *
     * Reads the LOCAL gazetteer (towns table), not Overpass. The live query
     * produced "could not load towns — pan slightly to retry" during ordinary
     * use, because free public Overpass gateway-times-out under load and
     * returns HTML error pages that parse as "no towns". A database bbox query
     * is instant and cannot fail that way; towns:import owns the slow,
     * retrying fetch where nobody is waiting.
     *
     * `needsImport` is reported separately from an empty list so the map can
     * say "this area has not been imported" rather than implying a lake.
     *
     * @return array{tooWide: bool, needsImport: bool, towns: array<int, array{name: string, lat: float, lng: float}>}
     */
    public function candidates(float $south, float $west, float $north, float $east): array
    {
        // Zoomed out past ~a state the dot layer is thousands of towns, which
        // is a slow render and an unusable map, not a data problem.
        if (($north - $south) > 5.0 || ($east - $west) > 5.0) {
            return ['tooWide' => true, 'needsImport' => false, 'towns' => []];
        }

        $existing = AreaServed::query()
            ->pluck('city')
            ->map(fn ($c) => mb_strtolower(trim((string) $c)))
            ->flip();

        $towns = \App\Models\Town::query()
            ->inBounds($south, $west, $north, $east)
            ->orderBy('name')
            ->limit(400)
            ->get(['name', 'latitude', 'longitude'])
            ->reject(fn ($t) => isset($existing[mb_strtolower($t->name)]))
            ->map(fn ($t) => ['name' => $t->name, 'lat' => $t->latitude, 'lng' => $t->longitude])
            ->values()
            ->all();

        return [
            'tooWide' => false,
            // Empty AND never imported = tell the user to import, not that the
            // region is empty. An imported box that genuinely holds no towns
            // reports needsImport = false and an empty list.
            'needsImport' => $towns === [] && ! \App\Models\TownImport::covers($south, $west, $north, $east),
            'towns' => $towns,
        ];
    }

    /**
     * Create an area from a map click — an empty spot or a candidate dot.
     * The state is re-validated server-side regardless of what the client
     * claimed, so the allowed-states rule cannot be bypassed from JS.
     */
    public function createFromMap(string $city, float $lat, float $lng): void
    {
        $city = trim($city);
        if ($city === '' || $lat === 0.0 || $lng === 0.0) {
            $this->mapFlash = 'Could not resolve a town at that point — try clicking closer to its center.';

            return;
        }

        $state = app(OpenStreetMapGeocoder::class)->reverseTown($lat, $lng)['state'];
        if ($state !== null && ! in_array($state, $this->allowedStates(), true)) {
            $this->mapFlash = "{$city} is in {$state} — outside this site's service states ("
                . implode(', ', $this->allowedStates())
                . '). Use the New Area form if this is intentional.';

            return;
        }

        $slug = \Illuminate\Support\Str::slug($city);
        $existing = AreaServed::where('slug', $slug)
            ->orWhereRaw('LOWER(city) = ?', [mb_strtolower($city)])
            ->first();
        if ($existing) {
            $this->mapFlash = "{$existing->city} is already a service area.";
            $this->dispatch('areas-map-updated', areas: $this->mapAreas());

            return;
        }

        $area = AreaServed::create([
            'city' => $city,
            'slug' => $slug,
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        $reused = $this->reuseSharedTownFacts($area);

        // Business-voice copy is always generated fresh for this tenant, even
        // when the town facts were reused — see reuseSharedTownFacts().
        \App\Jobs\RunSeoChannelSyncJob::dispatch(
            'seo:generate-area-content',
            ['--slug' => $area->slug, '--only' => 'intro,local_intro'],
        );

        $this->mapFlash = $reused
            ? "Added {$area->city}. Reused its landmarks and permit notes from another site, and queued this site's own intro copy."
            : "Added {$area->city}. Queued a job to pull its local details — refresh in a minute.";

        $this->dispatch('areas-map-updated', areas: $this->mapAreas());
    }

    /**
     * Copy a town's FACTUAL local info from any other tenant that already has it.
     *
     * Landmarks and permit notes describe the town, not the business — Metra
     * stations and a village's permit portal are the same facts whoever is
     * serving the street, so regenerating them per tenant burns Gemini calls to
     * arrive at the same answer.
     *
     * intro and local_intro are deliberately NOT copied. They are written in
     * the business's own voice ("GS Construction provides kitchen, bathroom and
     * whole-home remodeling…", "Remodeling in Arlington Heights means…"), so
     * copying them would put a contractor's marketing copy — and its company
     * name — on an interior designer's page. Those are always regenerated for
     * the tenant that owns the row.
     */
    protected function reuseSharedTownFacts(AreaServed $area): bool
    {
        $source = AreaServed::withoutSiteScope()
            ->where('slug', $area->slug)
            ->whereKeyNot($area->getKey())
            ->where(function ($q) {
                $q->whereNotNull('landmarks')->where('landmarks', '!=', '')
                    ->orWhere(fn ($q2) => $q2->whereNotNull('permit_notes')->where('permit_notes', '!=', ''));
            })
            ->first();

        if (! $source) {
            return false;
        }

        $area->forceFill(array_filter([
            'landmarks' => $source->landmarks ?: null,
            'permit_notes' => $source->permit_notes ?: null,
        ]))->save();

        return true;
    }

    /** @return array<int, array{id:int,city:string,slug:string,lat:float,lng:float,hasContent:bool,editUrl:string,publicUrl:string}> */
    public function mapAreas(): array
    {
        return AreaServed::query()
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->orderBy('city')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'city' => (string) $a->city,
                'slug' => (string) $a->slug,
                'lat' => (float) $a->latitude,
                'lng' => (float) $a->longitude,
                'hasContent' => $a->hasUniqueContent(),
                'editUrl' => route('admin.areas.edit', $a),
                'publicUrl' => url('/areas-served/' . $a->slug),
            ])
            ->all();
    }

    /**
     * Points the map should open framing: existing areas when there are any,
     * otherwise the site's declared markets (a new tenant's map must not open
     * on another tenant's metro — jpeterson's spans Chicago to Atlanta).
     *
     * @return array<int, array{lat: float, lng: float}>
     */
    public function initialFit(): array
    {
        $areas = collect($this->mapAreas())
            ->map(fn ($a) => ['lat' => $a['lat'], 'lng' => $a['lng']])
            ->all();

        if ($areas !== []) {
            return $areas;
        }

        return $this->mapMarkets();
    }

    /**
     * The site's declared markets, for the jump buttons above the map.
     *
     * Empty for a single-metro tenant like gsc, which has no markets.php —
     * the buttons then render not at all rather than as a row of one.
     *
     * @return array<int, array{label: string, lat: float, lng: float}>
     */
    public function mapMarkets(): array
    {
        return collect((array) config('markets.list', []))
            ->filter(fn ($m) => isset($m['lat'], $m['lng']))
            ->map(fn ($m) => [
                'label' => (string) ($m['city'] ?? $m['label'] ?? ''),
                'lat' => (float) $m['lat'],
                'lng' => (float) $m['lng'],
            ])
            ->values()
            ->all();
    }

    public function render()
    {
        $areas = AreaServed::query()
            ->when($this->search !== '', function ($q) {
                $term = "%{$this->search}%";
                $q->where('city', 'like', $term)->orWhere('slug', 'like', $term);
            })
            ->orderBy('city')
            ->paginate(25);

        return view('livewire.admin.area-list', [
            'areas' => $areas,
        ]);
    }
}
