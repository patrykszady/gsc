<?php

namespace App\Support\Seo;

use App\Support\Tenancy;
use Illuminate\Support\Facades\Cache;

/**
 * Microsoft Clarity's per-site credential (a project id + API token),
 * stored from /admin (POST platforms/clarity/credentials) and falling back
 * to the server's own MICROSOFT_CLARITY_ID / MICROSOFT_CLARITY_API_TOKEN
 * env values until imported — see seo:credentials-import-from-env.
 *
 * projectId() is also what the PUBLIC layout reads to decide whether to
 * print the Clarity tag (resources/views/components/layouts/app.blade.php),
 * and that layout renders on every page of every tenant — so it is cached
 * rather than hitting platform_settings on each request.
 */
final class ClaritySettings
{
    public const SETTING_PROJECT_ID = 'seo.clarity.project_id';

    public const SETTING_API_TOKEN = 'seo.clarity.api_token';

    /** "A few minutes" — long enough that a page storm doesn't hammer the DB, short enough that a stale env key doesn't linger if something bypasses the cache-clearing save/clear endpoints. */
    private const PROJECT_ID_CACHE_MINUTES = 5;

    /**
     * Cache::remember() re-reads Cache::get() to decide whether to run its
     * closure, and a plain get() cannot tell "nothing cached yet" from
     * "cached, and the value is null" — so a site with no Clarity id at all
     * (the common case for a tenant that has never used it) would miss the
     * cache and re-query platform_settings on every single page load. This
     * sentinel is what gets stored for "looked it up, there is none", so
     * that negative result is cached too.
     */
    private const NONE = '__none__';

    /**
     * Same-request memo on top of the cache store, keyed by the same
     * tenant-scoped string Cache::remember() uses below — NOT a single
     * scalar. `php artisan tenants:run "seo:clarity-sync"` runs the sync
     * once per site inside one PHP process via Tenancy::for() (see
     * TenantsRun), so a plain "first call wins" memo would hand every
     * tenant after the first one the previous tenant's project id. Keying
     * by Tenancy::cacheKey() makes a tenant switch mid-process just another
     * (uncached-yet) key instead of a stale hit.
     *
     * Mirrors Site::active()'s memo (see tests/TestCase.php's comment on
     * Site::forgetActive() for why a PHP static needs an explicit reset
     * between PHPUnit test methods — forgetProjectIdCache() below is that
     * reset, called from tearDown() too, not only from the admin save/clear
     * endpoints).
     *
     * @var array<string, ?string>
     */
    private static array $projectIdMemo = [];

    public function projectId(): ?string
    {
        $key = self::projectIdCacheKey();

        if (array_key_exists($key, self::$projectIdMemo)) {
            return self::$projectIdMemo[$key];
        }

        $cached = Cache::remember(
            $key,
            now()->addMinutes(self::PROJECT_ID_CACHE_MINUTES),
            fn () => PlatformSettingCredential::get(self::SETTING_PROJECT_ID, config('services.microsoft.clarity.project_id')) ?: self::NONE,
        );

        return self::$projectIdMemo[$key] = $cached === self::NONE ? null : $cached;
    }

    /**
     * Called by PlatformsController::saveClarityCredentials()/
     * clearClarityCredentials() so the public tag picks up a change on the
     * very next render instead of waiting out the TTL, and by
     * tests/TestCase.php between test methods so one test's stored id can
     * never leak into the next through the static memo. Clears every
     * tenant's memo entry, not just the current one — cheap, since this
     * only runs on a save/clear/test-teardown, never on the render path.
     */
    public static function forgetProjectIdCache(): void
    {
        Cache::forget(self::projectIdCacheKey());
        self::$projectIdMemo = [];
    }

    private static function projectIdCacheKey(): string
    {
        return Tenancy::cacheKey('seo.clarity.project_id');
    }

    public function apiToken(): ?string
    {
        return PlatformSettingCredential::get(self::SETTING_API_TOKEN, config('services.microsoft.clarity.api_token'));
    }

    /** Not a credential — Clarity's fixed export API base URL. */
    public function baseUrl(): string
    {
        return (string) config('services.microsoft.clarity.base_url', 'https://www.clarity.ms/export-data/api/v1');
    }

    public function isConfigured(): bool
    {
        return filled($this->projectId()) && filled($this->apiToken());
    }

    /** 'admin' | 'env' | null — 'admin' only once BOTH fields are stored, not just one. */
    public function source(): ?string
    {
        if (PlatformSettingCredential::configured(self::SETTING_PROJECT_ID, self::SETTING_API_TOKEN)) {
            return 'admin';
        }

        $envConfigured = filled(config('services.microsoft.clarity.project_id'))
            && filled(config('services.microsoft.clarity.api_token'));

        return $envConfigured ? 'env' : null;
    }
}
