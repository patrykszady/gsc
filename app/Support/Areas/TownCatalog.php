<?php

namespace App\Support\Areas;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Town;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Every US place that could become a service-area page: the Census
 * gazetteer (resources/data/us-towns.json, 33,000 cities, villages, towns
 * and CDPs in every state), minus the areas that already exist. Without a
 * search the list is the home region ranked by the demand we can see —
 * researched search volume, the Business Profile service area, distance;
 * with a search it is the whole country, closest name match first.
 *
 * Labels are "Name, ST". Slugs stay bare in the home state (palatine) and
 * carry the state elsewhere (atlanta-ga) so the same name in two states
 * never collides.
 */
class TownCatalog
{
    public const FILE = 'data/us-towns.json';

    public const STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'PR' => 'Puerto Rico',
    ];

    /** @return list<array{name: string, state: string, lat: float, lng: float, kind: string, land_sqmi: float}> */
    public static function all(): array
    {
        static $towns = null;
        if ($towns === null) {
            $path = resource_path(self::FILE);
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $towns = [];
            foreach ((array) ($data['towns'] ?? []) as $r) {
                $towns[] = ['name' => (string) $r[0], 'state' => (string) $r[1], 'lat' => (float) $r[2], 'lng' => (float) $r[3], 'kind' => (string) $r[4], 'land_sqmi' => (float) $r[5]];
            }
        }

        return $towns;
    }

    public static function homeState(): string
    {
        return strtoupper((string) (config('brand.address.state') ?: config('brand.state') ?: 'IL'));
    }

    public static function stateName(string $code): string
    {
        return self::STATES[strtoupper($code)] ?? strtoupper($code);
    }

    /** "Atlanta, GA" */
    public static function label(array $town): string
    {
        return $town['name'].', '.$town['state'];
    }

    /** Bare slug at home (palatine), state-suffixed elsewhere (atlanta-ga). */
    public static function slugFor(array $town): string
    {
        $slug = Str::slug($town['name']);

        return strtoupper($town['state']) === self::homeState() ? $slug : $slug.'-'.strtolower($town['state']);
    }

    /** The city string an area row carries: bare at home, "Name, ST" elsewhere. */
    public static function cityFor(array $town): string
    {
        return strtoupper($town['state']) === self::homeState() ? $town['name'] : self::label($town);
    }

    /** Miles from the office (config seo.map_pack center). */
    public static function distance(float $lat, float $lng): float
    {
        $lat0 = (float) config('seo.map_pack.center_lat', 42.102847);
        $lng0 = (float) config('seo.map_pack.center_lng', -87.9275628);
        $p1 = deg2rad($lat0);
        $p2 = deg2rad($lat);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng - $lng0) / 2) ** 2;

        return round(2 * 3958.8 * asin(sqrt($a)), 1);
    }

    /**
     * One town by "Name, ST", slug (either form) or bare name. A bare name
     * that exists in several states resolves to the home state first, then
     * the nearest.
     */
    public static function find(string $nameOrSlug): ?array
    {
        $key = Str::slug($nameOrSlug);
        $matches = [];
        foreach (self::all() as $t) {
            if ($key === Str::slug(self::label($t)) || $key === self::slugFor($t) || $key === Str::slug($t['name'])) {
                $matches[] = $t;
            }
        }
        if ($matches === []) {
            return null;
        }
        usort($matches, fn ($a, $b) => [strtoupper($a['state']) === self::homeState() ? 0 : 1, self::distance($a['lat'], $a['lng'])] <=> [strtoupper($b['state']) === self::homeState() ? 0 : 1, self::distance($b['lat'], $b['lng'])]);

        return $matches[0];
    }

    /**
     * Towns not yet on the site. No search: the home region within
     * candidate_radius_miles, best candidates first. With a search: the
     * whole country, names starting with the query first, bigger places
     * before smaller ones.
     *
     * @return list<array<string, mixed>>
     */
    public static function candidates(?string $search = null, int $limit = 60, ?float $maxMiles = null): array
    {
        $existing = AreaServed::query()->get(['city', 'slug'])
            ->flatMap(fn ($a) => [Str::slug((string) $a->city), (string) $a->slug])->filter()->unique()->flip()->all();
        $served = collect((array) config('gbp-services.service_areas', []))
            ->map(fn ($s) => Str::slug(trim((string) Str::before((string) $s, ','))))->filter()->flip()->all();
        $projects = Project::query()->where('is_published', true)->whereNotNull('location')->pluck('location')
            ->map(fn ($l) => Str::slug(trim((string) Str::before((string) $l, ','))))->countBy()->all();
        $volume = [];
        $keywords = [];
        if (Schema::hasTable('seo_keywords')) {
            foreach (Tenancy::table('seo_keywords')->whereNotNull('city')->selectRaw('city, SUM(volume) v, COUNT(*) n')->groupBy('city')->get() as $r) {
                $volume[Str::slug((string) $r->city)] = (int) $r->v;
                $keywords[Str::slug((string) $r->city)] = (int) $r->n;
            }
        }
        $q = trim((string) $search);
        $qSlug = $q !== '' ? Str::slug($q) : '';
        $qLower = mb_strtolower($q);
        $maxMiles = $maxMiles ?? (float) config('areas.candidate_radius_miles', 35);
        $home = self::homeState();

        $out = [];
        foreach (self::all() as $t) {
            $slug = self::slugFor($t);
            $bare = Str::slug($t['name']);
            $label = self::label($t);
            if (isset($existing[$slug]) || (strtoupper($t['state']) === $home && isset($existing[$bare]))) {
                continue;
            }
            if ($qSlug !== '') {
                $labelSlug = Str::slug($label);
                if (! str_contains($labelSlug, $qSlug) && ! str_starts_with($bare, $qSlug)) {
                    continue;
                }
            }
            $distance = self::distance($t['lat'], $t['lng']);
            if ($qSlug === '' && $distance > $maxMiles) {
                continue;
            }
            $inHome = strtoupper($t['state']) === $home;
            $p = $inHome ? (int) ($projects[$bare] ?? 0) : 0;
            $v = $inHome ? (int) ($volume[$bare] ?? 0) : 0;
            $inArea = $inHome && isset($served[$bare]);
            $out[] = [
                'name' => $t['name'], 'state' => $t['state'], 'label' => $label, 'slug' => $slug, 'city' => self::cityFor($t), 'kind' => $t['kind'],
                'latitude' => $t['lat'], 'longitude' => $t['lng'], 'distance_mi' => $distance, 'land_sqmi' => $t['land_sqmi'],
                'projects' => $p, 'researched_volume' => $v, 'researched_keywords' => $inHome ? (int) ($keywords[$bare] ?? 0) : 0,
                'in_service_area' => $inArea,
                'score' => round($p * 60 + min($v, 3000) / 10 + ($inArea ? 100 : 0) - $distance * 2, 1),
                'why' => implode(' · ', array_filter([
                    $p ? "{$p} project".($p === 1 ? '' : 's') : null,
                    $v ? number_format($v).' searches/mo researched' : null,
                    $inArea ? 'Business Profile service area' : null,
                    $t['kind'] !== 'CDP' ? $t['kind'] : 'unincorporated',
                    number_format($distance).' mi from the office',
                ])),
                '_starts' => $qLower !== '' && str_starts_with(mb_strtolower($t['name']), $qLower) ? 1 : 0,
            ];
        }
        if ($qSlug !== '') {
            foreach (self::neighbourhoodMatches($qSlug, $qLower, $existing) as $row) {
                $out[] = $row;
            }
            usort($out, fn ($a, $b) => [$b['_starts'], $b['land_sqmi'], $a['distance_mi']] <=> [$a['_starts'], $a['land_sqmi'], $b['distance_mi']]);
        } else {
            usort($out, fn ($a, $b) => [$b['score'], $a['distance_mi']] <=> [$a['score'], $b['distance_mi']]);
        }

        return array_map(fn ($c) => array_diff_key($c, ['_starts' => true]), array_slice($out, 0, max(1, $limit)));
    }

    /**
     * Neighbourhood/suburb/quarter towns (App\Models\AreaServed::NEIGHBOURHOOD_KINDS)
     * from the shared `towns` gazetteer whose name matches the typed query —
     * never on an untyped browse, the same restraint the map's dots now
     * apply (App\Http\Controllers\Api\Admin\V1\AreaMapController::candidates)
     * — for the reason the owner gave: 128 of them on one city's map is too
     * much, but hiding a real place by name entirely just because it isn't
     * in the Census catalog (self::all()) is wrong the other way. They are
     * NOT in that catalog at all, so without this merge a neighbourhood was
     * simply unsearchable, dot or no dot.
     *
     * Same addability gate as AreaMapController::createFromMap — a
     * subdivision only surfaces here INSIDE one of this tenant's major-city
     * markets (App\Support\Areas\MajorCityMarkets) — so nothing this method
     * offers can then be refused when someone tries to add it.
     *
     * @param  array<string, int>  $existing  slug/bare-name => 1, from candidates()
     * @return list<array<string, mixed>>
     */
    protected static function neighbourhoodMatches(string $qSlug, string $qLower, array $existing): array
    {
        $home = self::homeState();
        $rows = [];

        $towns = Town::query()->whereIn('kind', AreaServed::NEIGHBOURHOOD_KINDS)->get(['name', 'state', 'latitude', 'longitude', 'kind']);

        foreach ($towns as $town) {
            $bare = Str::slug((string) $town->name);
            if (! str_contains($bare, $qSlug)) {
                continue;
            }

            $market = MajorCityMarkets::matchingMarket((float) $town->latitude, (float) $town->longitude);
            if ($market === null) {
                continue;
            }

            // towns.state is almost always null (the OSM gazetteer carries
            // no administrative boundary, only a point) — the market it sits
            // inside knows its state reliably, and every declared market
            // carries one.
            $state = strtoupper((string) ($town->state ?: ($market['state'] ?? '') ?: $home));
            $inHome = $state === $home;
            $slug = $inHome ? $bare : $bare.'-'.strtolower($state);

            if (isset($existing[$slug]) || ($inHome && isset($existing[$bare]))) {
                continue;
            }

            $marketName = trim((string) ($market['city'] ?? $market['label'] ?? ''));
            $distance = self::distance((float) $town->latitude, (float) $town->longitude);

            $rows[] = [
                'name' => $town->name,
                'state' => $state,
                // Obviously a neighbourhood, not a town of its own — the
                // "carrying kind and an obviously-a-neighbourhood label"
                // half of the rule: a bare "Logan Square" reads exactly like
                // any other candidate town until the parenthetical says
                // otherwise.
                'label' => $marketName !== '' ? "{$town->name} ({$marketName} {$town->kind})" : "{$town->name} ({$town->kind})",
                'slug' => $slug,
                'city' => $inHome ? (string) $town->name : $town->name.', '.$state,
                'kind' => $town->kind,
                'latitude' => (float) $town->latitude,
                'longitude' => (float) $town->longitude,
                'distance_mi' => $distance,
                'land_sqmi' => 0.0,
                'projects' => 0,
                'researched_volume' => 0,
                'researched_keywords' => 0,
                'in_service_area' => false,
                'score' => 0.0,
                'why' => $marketName !== ''
                    ? "{$marketName} {$town->kind} · ".number_format($distance).' mi from the office'
                    : ucfirst((string) $town->kind),
                '_starts' => $qLower !== '' && str_starts_with(mb_strtolower((string) $town->name), $qLower) ? 1 : 0,
            ];
        }

        return $rows;
    }
}
