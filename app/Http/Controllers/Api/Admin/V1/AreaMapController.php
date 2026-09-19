<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunSeoChannelSyncJob;
use App\Models\AreaServed;
use App\Models\Town;
use App\Models\TownImport;
use App\Services\OpenStreetMapGeocoder;
use App\Support\Areas\MajorCityMarkets;
use App\Support\Areas\StateLocator;
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
            // kind travels with each town so the ADMIN decides what earns a dot
            // and what is merely a clickable label. That policy belongs in one
            // place, and the admin is the one place both sites share.
            ->get(['name', 'latitude', 'longitude', 'kind'])
            ->reject(fn ($t) => isset($existing[mb_strtolower($t->name)]))
            ->map(fn ($t) => [
                'name' => $t->name,
                'lat' => $t->latitude,
                'lng' => $t->longitude,
                'kind' => (string) ($t->kind ?? ''),
            ])
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

        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];

        // The gazetteer answers this, not a reverse-geocode: local, instant,
        // and it returns the name shown on the map. Reverse-geocoding the
        // "Riverside" label gives "Lincoln Charter Township" — correct and
        // useless for adding a service area. Hamlets are included here even
        // though they get no dot, which is the point: a place the map labels
        // but does not dot is exactly what this resolves.
        //
        // No nearby town means no answer, which is what keeps a click on open
        // countryside from offering to add whatever was nearest.
        $nearest = Town::nearestTo($lat, $lng);

        $town = [
            'city' => $nearest?->name,
            'state' => $nearest?->state ?: StateLocator::forTown((string) $nearest?->name, $lat, $lng),
        ];
        $allowedStates = $this->allowedStates();

        // The nearest gazetteer town can be a neighbourhood even though it
        // got no dot (see AreaMapController::candidates) — a click on its
        // label must obey the SAME addability rule createFromMap enforces,
        // or "allowed" here would promise an add that createFromMap then
        // refuses.
        $isNeighbourhood = $nearest !== null && in_array($nearest->kind, AreaServed::NEIGHBOURHOOD_KINDS, true);

        return response()->json([
            'data' => $town + [
                // No town means nothing to allow.
                'allowed' => $town['city'] !== null
                    && $town['state'] !== null
                    && in_array($town['state'], $allowedStates, true)
                    && (! $isNeighbourhood || MajorCityMarkets::contains($lat, $lng)),
                'allowed_states' => $allowedStates,
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
            // The candidate's OSM place=* value, passed straight through from
            // candidates()'s 'kind' field when this click was on a dot rather
            // than empty ground. Optional — a typed-in area has none.
            'kind' => ['nullable', 'string', 'max:32'],
        ]);

        $city = trim($data['city']);
        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        $kind = filled($data['kind'] ?? null) ? trim((string) $data['kind']) : null;

        if ($city === '' || $lat === 0.0 || $lng === 0.0) {
            return response()->json([
                'data' => [
                    'created' => false,
                    'message' => 'Could not resolve a town at that point — try clicking closer to its center.',
                    'area' => null,
                ],
            ]);
        }

        // The bundled ZIP gazetteer answers this locally in ~15ms. It used to be
        // a Nominatim reverse-geocode, ~1.5s of every add — and its 30-day cache
        // never helped, because a town being added for the first time is always
        // a miss, which is the only click that matters. Nominatim stays as the
        // fallback for a point the gazetteer cannot place.
        $state = StateLocator::forTown($city, $lat, $lng)
            ?? app(OpenStreetMapGeocoder::class)->reverseTown($lat, $lng)['state'];
        if ($state !== null && ! in_array($state, $this->allowedStates(), true)) {
            return response()->json([
                'data' => [
                    'created' => false,
                    'message' => "{$city} is in {$state} — outside this site's service states (".implode(', ', $this->allowedStates()).'). Use the New Area form if this is intentional.',
                    'area' => null,
                ],
            ]);
        }

        // Neighbourhoods get no dot any more (the admin decides what is
        // drawn — see candidates() above), but this endpoint is also reached
        // from a resolve-town click and from anywhere a caller supplies a
        // 'kind', so the rule still has to be enforced here: a big city's
        // named subdivision is only a real, addable place inside one of
        // THIS site's major-city markets — outside one it is at best an
        // unincorporated area with a confusing name, not somewhere to build
        // a page around. Same refusal shape as the out-of-state check above.
        if ($kind !== null && in_array($kind, AreaServed::NEIGHBOURHOOD_KINDS, true) && ! MajorCityMarkets::contains($lat, $lng)) {
            return response()->json([
                'data' => [
                    'created' => false,
                    'message' => "{$city} is a {$kind} — those are only addable inside one of this site's major-city markets. Use the New Area form if this is intentional.",
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
            'kind' => $kind,
        ]);

        // Every field is written for this site: nothing is copied from
        // another site's row for the same town (2026-09-11 — the central
        // admin promises "written as soon as it is added", per site).
        RunSeoChannelSyncJob::dispatch(
            'seo:generate-area-content',
            ['--slug' => $area->slug],
        );

        return response()->json([
            'data' => [
                'created' => true,
                'message' => "Added {$area->city}. Its page copy is being written — refresh in a minute.",
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
                // kind rides with every persisted area, not just the create
                // response, so the admin can style a neighbourhood after a
                // reload as well as right after adding it.
                'kind' => $a->kind,
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
}
