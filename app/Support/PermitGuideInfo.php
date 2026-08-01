<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Per-municipality building permit guide data, researched from official
 * village/city building-department pages, keyed by AreaServed slug.
 *
 * Source of truth is config/permit-guides.php.
 *
 * It used to be storage/app/private/permit-guides.json, which .gitignore
 * excludes (/storage/app) — so the data lived only on the developer's machine
 * and never deployed. In production all() returned [], every /permits/{slug}
 * hit abort_unless() and 404'd, and since the sitemap is generated locally
 * where the file DOES exist, all 10 permit URLs were published to Google as
 * live pages. The JSON is still read as a fallback so a machine that has it
 * but not the config keeps working.
 *
 * Entry shape (all strings unless noted):
 *   town, source_urls (string[]), permit_when_required, application_process,
 *   review_time, inspections, fees, contractor_registration,
 *   notable_quirks (string or string[]), researched_at
 */
class PermitGuideInfo
{
    public const PATH = 'permit-guides.json';
    public const CACHE_KEY = 'permit_guide_info:v2';

    /** @return array<string,array<string,mixed>> keyed by area slug */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(12), function (): array {
            $fromConfig = config('permit-guides', []);
            if (is_array($fromConfig) && $fromConfig !== []) {
                return $fromConfig;
            }

            // Legacy fallback — see the class docblock.
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
