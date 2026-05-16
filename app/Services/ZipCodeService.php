<?php

namespace App\Services;

use App\Models\AreaServed;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the ZIP -> {city, area, projects} map used for /service-area/{zip}
 * landing pages and sitemap generation.
 *
 * Source of truth: public/project-zipcodes.csv (id, zip_code) joined with
 * the projects table to derive the city per ZIP. Each ZIP is mapped to the
 * city of its first matching published project, then matched (best-effort)
 * to an AreaServed row by city name.
 */
class ZipCodeService
{
    private const CACHE_KEY = 'service_area:zip_map:v1';
    private const CACHE_TTL = 86400; // 24h

    /**
     * @return array<string, array{city: string, area_slug: ?string, project_ids: array<int,int>, count: int}>
     */
    public function getZipMap(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $csv = public_path('project-zipcodes.csv');
            if (! is_file($csv)) {
                return [];
            }

            $handle = fopen($csv, 'r');
            if ($handle === false) {
                return [];
            }
            $header = fgetcsv($handle);
            if (! $header) {
                fclose($handle);
                return [];
            }
            $cols = array_map(fn ($c) => strtolower(trim((string) $c)), $header);
            $idIdx = array_search('id', $cols, true);
            $zipIdx = array_search('zip_code', $cols, true);
            if ($idIdx === false || $zipIdx === false) {
                fclose($handle);
                return [];
            }

            // project_id => zip
            $projectZip = [];
            while (($row = fgetcsv($handle)) !== false) {
                $pid = (int) ($row[$idIdx] ?? 0);
                $zip = preg_replace('/\D/', '', (string) ($row[$zipIdx] ?? ''));
                if ($pid > 0 && strlen((string) $zip) === 5) {
                    $projectZip[$pid] = $zip;
                }
            }
            fclose($handle);

            if (empty($projectZip)) {
                return [];
            }

            // Pull only published projects for these ids.
            $projects = Project::query()
                ->whereIn('id', array_keys($projectZip))
                ->where('is_published', true)
                ->get(['id', 'location']);

            // city (lowercased) => area slug
            $areaBySlug = AreaServed::all(['city', 'slug'])
                ->mapWithKeys(fn ($a) => [mb_strtolower(trim($a->city)) => $a->slug])
                ->all();

            // zip => [city, area_slug, project_ids[]]
            $zipMap = [];
            foreach ($projects as $p) {
                $zip = $projectZip[$p->id] ?? null;
                if (! $zip) {
                    continue;
                }
                $city = trim(explode(',', (string) $p->location)[0] ?? '');
                if ($city === '') {
                    continue;
                }
                if (! isset($zipMap[$zip])) {
                    $zipMap[$zip] = [
                        'city' => $city,
                        'area_slug' => $areaBySlug[mb_strtolower($city)] ?? null,
                        'project_ids' => [],
                        'count' => 0,
                    ];
                }
                $zipMap[$zip]['project_ids'][] = (int) $p->id;
                $zipMap[$zip]['count']++;
            }

            ksort($zipMap);
            return $zipMap;
        });
    }

    /**
     * @return array{city: string, area_slug: ?string, project_ids: array<int,int>, count: int}|null
     */
    public function find(string $zip): ?array
    {
        $zip = preg_replace('/\D/', '', $zip);
        $map = $this->getZipMap();
        return $map[$zip] ?? null;
    }

    /**
     * Convenience: list of ZIPs grouped by city.
     *
     * @return array<string, array<int, string>>
     */
    public function groupedByCity(): array
    {
        $grouped = [];
        foreach ($this->getZipMap() as $zip => $info) {
            $grouped[$info['city']][] = (string) $zip;
        }
        ksort($grouped);
        return $grouped;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
