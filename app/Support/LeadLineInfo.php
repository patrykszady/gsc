<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Per-municipality lead service line replacement data, researched from
 * official village/water-utility pages (see the lead-line-programs-research
 * workflow) and stored at resources/data/lead-service-lines.json keyed by
 * AreaServed slug.
 *
 * IN THE REPO on purpose. It lived in storage/app/private, which .gitignore
 * excludes wholesale — so the file existed only on the machine that ran the
 * research. Production never had it, all() returned [], and every one of the
 * 66 lead-pipe pages silently fell back to noindex while both the area and
 * ZIP templates kept linking to them. 302 KB of sourced municipal research
 * (source_urls + researched_at per town), invisible for months because of a
 * gitignore rule. Content data that pages depend on belongs in version
 * control like the code that renders it.
 *
 * Entry shape (all strings unless noted):
 *   city, found_official_info (bool), source_urls (string[]), water_system,
 *   has_replacement_program (bool), program_name, cost_coverage,
 *   homeowner_cost, how_to_check_line, how_to_apply, notes, researched_at
 *
 * Pages render for every area; entries without found_official_info render
 * the generic Illinois-law content and are noindexed (thin-page guard).
 */
class LeadLineInfo
{
    public const PATH = 'data/lead-service-lines.json';
    public const CACHE_KEY = 'lead_line_info:v2';

    /** @return array<string,array<string,mixed>> keyed by area slug */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(12), function (): array {
            $path = resource_path(self::PATH);

            // Legacy location, so a machine with a newer local research file
            // than the committed one keeps working during the transition.
            if (! is_file($path) && Storage::disk('local')->exists('lead-service-lines.json')) {
                $data = json_decode((string) Storage::disk('local')->get('lead-service-lines.json'), true);

                return is_array($data) ? $data : [];
            }

            if (! is_file($path)) {
                return [];
            }

            $data = json_decode((string) file_get_contents($path), true);

            return is_array($data) ? $data : [];
        });
    }

    /** @return array<string,mixed>|null */
    public static function forSlug(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /** Entries good enough to index: official info actually found. */
    public static function hasOfficialInfo(string $slug): bool
    {
        return (bool) (self::forSlug($slug)['found_official_info'] ?? false);
    }

    public static function bust(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
