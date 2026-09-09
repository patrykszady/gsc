<?php

namespace App\Support\Areas;

use App\Models\AreaServed;
use App\Models\Project;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Every place around the office that could become a service-area page:
 * US Census places within 45 miles (resources/data/chicagoland-towns.json),
 * minus the areas that already exist, ranked by the demand we can see —
 * finished projects there, researched search volume, membership in the
 * Business Profile service areas, and distance.
 */
class TownCatalog
{
    public const FILE = 'data/chicagoland-towns.json';

    /** @return list<array{name: string, kind: string, lat: float, lng: float, distance_mi: float, land_sqmi: float}> */
    public static function all(): array
    {
        static $towns = null;
        if ($towns === null) {
            $path = resource_path(self::FILE);
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $towns = is_array($data) ? array_values((array) ($data['towns'] ?? [])) : [];
        }

        return $towns;
    }

    /** One town by name (case-insensitive) or slug, or null. */
    public static function find(string $nameOrSlug): ?array
    {
        $key = Str::slug($nameOrSlug);
        foreach (self::all() as $t) {
            if (Str::slug($t['name']) === $key) {
                return $t;
            }
        }

        return null;
    }

    /**
     * Towns not yet on the site, best candidates first.
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
        $q = $search !== null ? Str::slug($search) : '';
        $maxMiles = $maxMiles ?? (float) config('areas.candidate_radius_miles', 35);

        $out = [];
        foreach (self::all() as $t) {
            $slug = Str::slug($t['name']);
            if (isset($existing[$slug]) || (float) $t['distance_mi'] > $maxMiles) {
                continue;
            }
            if ($q !== '' && ! str_contains($slug, $q)) {
                continue;
            }
            $p = (int) ($projects[$slug] ?? 0);
            $v = (int) ($volume[$slug] ?? 0);
            $inArea = isset($served[$slug]);
            $out[] = [
                'name' => $t['name'], 'slug' => $slug, 'kind' => $t['kind'],
                'latitude' => $t['lat'], 'longitude' => $t['lng'], 'distance_mi' => (float) $t['distance_mi'],
                'projects' => $p, 'researched_volume' => $v, 'researched_keywords' => (int) ($keywords[$slug] ?? 0),
                'in_service_area' => $inArea,
                'score' => round($p * 60 + min($v, 3000) / 10 + ($inArea ? 100 : 0) - (float) $t['distance_mi'] * 2, 1),
                'why' => implode(' · ', array_filter([
                    $p ? "{$p} project" . ($p === 1 ? '' : 's') : null,
                    $v ? number_format($v) . ' searches/mo researched' : null,
                    $inArea ? 'Business Profile service area' : null,
                    $t['distance_mi'] . ' mi',
                ])),
            ];
        }
        usort($out, fn ($a, $b) => [$b['score'], $a['distance_mi']] <=> [$a['score'], $b['distance_mi']]);

        return array_slice($out, 0, max(1, $limit));
    }
}
