<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Per-municipality building permit guide data, researched from official
 * village/city building-department pages and stored at
 * storage/app/private/permit-guides.json keyed by AreaServed slug.
 *
 * Entry shape (all strings unless noted):
 *   town, source_urls (string[]), permit_when_required, application_process,
 *   review_time, inspections, fees, contractor_registration,
 *   notable_quirks (string or string[]), researched_at
 */
class PermitGuideInfo
{
    public const PATH = 'permit-guides.json';
    public const CACHE_KEY = 'permit_guide_info:v1';

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

    public static function bust(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
