<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunSeoChannelSyncJob;
use App\Models\AreaServed;
use App\Models\Town;
use App\Models\TownImport;
use App\Services\OpenStreetMapGeocoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * gsc-only coverage-map endpoints for the central admin's AreaList/AreaForm
 * pixel-parity port — ported from app/Livewire/Admin/AreaList.php
 * (mapAreas, initialFit, mapMarkets, allowedStates, resolveTown, candidates,
 * createFromMap). Gated on the 'areas-map' ping capability, which jpeterson
 * does not declare.
 *
 * Registered under /api/admin/v1/areas-map/* rather than nested under
 * /areas/* — Route::apiResource('areas', AreaController::class) in
 * routes/api.php (not owned by this task) already claims GET areas/{area},
 * so a sibling "areas/map" segment would be swallowed by that wildcard
 * before this controller ever saw the request.
 */
class AreaMapController extends Controller
{
    /** Coverage-map bootstrap payload: existing areas, jump-to markets, initial framing, allowed states, browser key. */
    public function map(): JsonResponse
    {
        return response()->json([
            'data' => [
                'areas' => $this->mapAreas(),
                'markets' => $this->mapMarkets(),
                'initial_fit' => $this->initialFit(),
                'allowed_states' => $this->allowedStates(),
                'maps_browser_key' => config('services.google.maps_browser_key'),
            ],
        ]);
    }

    /**
     * Named towns in the current viewport that are NOT yet service areas —
     * the orange candidate dots. See AreaList::candidates() for why this
     * reads the local gazetteer rather than live Overpass.
     */
    public function candidates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'south' => ['required', 'numeric'],
            'west' => ['required', 'numeric'],
            'north' => ['required', 'numeric'],
            'east' => ['required', 'numeric'],
        ]);

        $south = (float) $data['south'];
        $west = (float) $data['west'];
        $north = (float) $data['north'];
        $east = (float) $data['east'];

        if (($north - $south) > 5.0 || ($east - $west) > 5.0) {
            return response()->json(['data' => ['too_wide' => true, 'needs_import' => false, 'towns' => []]]);
        }

        $existing = AreaServed::query()
            ->pluck('city')
            ->map(fn ($c) => mb_strtolower(trim((string) $c)))
            ->flip();

        $towns = Town::query()
            ->inBounds($south, $west, $north, $east)
            ->orderBy('name')
            ->limit(400)
            ->get(['name', 'latitude', 'longitude'])
            ->reject(fn ($t) => isset($existing[mb_strtolower($t->name)]))
            ->map(fn ($t) => ['name' => $t->name, 'lat' => $t->latitude, 'lng' => $t->longitude])
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'too_wide' => false,
                'needs_import' => $towns === [] && ! TownImport::covers($south, $west, $north, $east),
                'towns' => $towns,
            ],
        ]);
    }

    /** What town is under this map click? Server-side Nominatim reverse geocode. */
    public function resolveTown(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $town = app(OpenStreetMapGeocoder::class)->reverseTown((float) $data['lat'], (float) $data['lng']);
        $allowed = $this->allowedStates();

        return response()->json([
            'data' => $town + [
                'allowed' => $town['state'] !== null && in_array($town['state'], $allowed, true),
                'allowed_states' => $allowed,
            ],
        ]);
    }

    /**
     * Create an area from a map click — an empty spot or a candidate dot.
     * The state is re-validated server-side regardless of what the client
     * claimed, mirroring AreaList::createFromMap() exactly.
     */
    public function createFromMap(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city' => ['required', 'string', 'max:120'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $city = trim($data['city']);
        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];

        if ($city === '' || $lat === 0.0 || $lng === 0.0) {
            return response()->json([
                'data' => [
                    'created' => false,
                    'message' => 'Could not resolve a town at that point — try clicking closer to its center.',
                    'area' => null,
                ],
            ]);
        }

        $state = app(OpenStreetMapGeocoder::class)->reverseTown($lat, $lng)['state'];
        if ($state !== null && ! in_array($state, $this->allowedStates(), true)) {
            return response()->json([
                'data' => [
                    'created' => false,
                    'message' => "{$city} is in {$state} — outside this site's service states (".implode(', ', $this->allowedStates()).'). Use the New Area form if this is intentional.',
                    'area' => null,
                ],
            ]);
        }

        $slug = Str::slug($city);
        $existing = AreaServed::where('slug', $slug)
            ->orWhereRaw('LOWER(city) = ?', [mb_strtolower($city)])
            ->first();
        if ($existing) {
            return response()->json([
                'data' => [
                    'created' => false,
                    'message' => "{$existing->city} is already a service area.",
                    'area' => $existing->toApiArray(),
                ],
            ]);
        }

        $area = AreaServed::create([
            'city' => $city,
            'slug' => $slug,
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        $reused = $this->reuseSharedTownFacts($area);

        RunSeoChannelSyncJob::dispatch(
            'seo:generate-area-content',
            ['--slug' => $area->slug, '--only' => 'intro,local_intro'],
        );

        return response()->json([
            'data' => [
                'created' => true,
                'message' => $reused
                    ? "Added {$area->city}. Reused its landmarks and permit notes from another site, and queued this site's own intro copy."
                    : "Added {$area->city}. Queued a job to pull its local details — refresh in a minute.",
                'area' => $area->fresh()->toApiArray(),
            ],
        ]);
    }

    /** States this tenant serves, for validating map adds. See AreaList::allowedStates(). */
    protected function allowedStates(): array
    {
        $states = array_values(array_unique(array_filter(
            array_column((array) config('markets.list', []), 'state'),
        )));

        return $states !== []
            ? $states
            : [(string) config('services.google.business_profile.geocode_state', 'IL')];
    }

    /** @return array<int, array{id:int,city:string,slug:string,lat:float,lng:float,has_content:bool,edit_url:string,public_url:string}> */
    protected function mapAreas(): array
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
                'has_content' => $a->hasUniqueContent(),
                'public_url' => url('/areas-served/'.$a->slug),
            ])
            ->all();
    }

    /** @return array<int, array{lat: float, lng: float}> */
    protected function initialFit(): array
    {
        $areas = collect($this->mapAreas())
            ->map(fn ($a) => ['lat' => $a['lat'], 'lng' => $a['lng']])
            ->all();

        return $areas !== [] ? $areas : $this->mapMarkets();
    }

    /** @return array<int, array{label: string, lat: float, lng: float}> */
    protected function mapMarkets(): array
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

    /** Copy a town's FACTUAL local info from any other tenant that already has it. See AreaList::reuseSharedTownFacts(). */
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
}
