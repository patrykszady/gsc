<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A top-precedence <title>/meta-description override for a single URL path.
 * Read on every request via SEOBuilder::build(), so the full set is cached and
 * busted on write.
 */
class SeoPathOverride extends Model
{
    protected $guarded = [];

    public const CACHE_KEY = 'seo:path_overrides';

    protected static function booted(): void
    {
        $bust = fn () => Cache::forget(self::CACHE_KEY);
        static::saved($bust);
        static::deleted($bust);
    }

    /** Normalize a URL or path to the stored key form (home = '/'). */
    public static function normalizePath(string $urlOrPath): string
    {
        $path = str_contains($urlOrPath, '://') ? (parse_url($urlOrPath, PHP_URL_PATH) ?: '/') : $urlOrPath;
        $path = trim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /**
     * Cached map of path => ['title' => ?string, 'description' => ?string].
     *
     * @return array<string,array{title:?string,description:?string}>
     */
    public static function map(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(6), function (): array {
            return static::query()
                ->get(['path', 'title', 'description'])
                ->keyBy('path')
                ->map(fn ($r) => ['title' => $r->title, 'description' => $r->description])
                ->all();
        });
    }

    /** @return array{title:?string,description:?string}|null */
    public static function forPath(string $urlOrPath): ?array
    {
        return static::map()[self::normalizePath($urlOrPath)] ?? null;
    }
}
