<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Per-municipality lead service line replacement data, researched from
 * official village/water-utility pages (see the lead-line-programs-research
 * workflow) and stored at storage/app/lead-service-lines.json keyed by
 * AreaServed slug.
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
    public const PATH = 'lead-service-lines.json';
    public const CACHE_KEY = 'lead_line_info:v1';

    /** @return array<string,array<string,mixed>> keyed by area slug */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(12), function (): array {
            $disk = Storage::disk('local');
            if (! $disk->exists(self::PATH)) {
                return [];
            }

            $data = json_decode((string) $disk->get(self::PATH), true);

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
