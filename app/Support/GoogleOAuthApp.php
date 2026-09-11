<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * This site's own Google OAuth client — the "OAuth 2.0 Client ID" made in
 * the business's Google Cloud project — which Google Business Profile and
 * Search Console both sign in with.
 *
 * Every site has its own Google account and its own profiles, so the
 * client id and secret are per site and entered from the central admin
 * (upload the client JSON Google Cloud Console hands out, or paste the two
 * values), stored encrypted in platform_settings, never shared between
 * sites. apply() overlays them onto config('services.google.business_profile')
 * and config('services.google.search_console') at boot so the two services
 * keep reading config exactly as before; the GOOGLE_BUSINESS_PROFILE_* env
 * values stay as a fallback for a site that still sets them that way.
 *
 * Google only completes the sign-in when the callback URL is registered on
 * that client as an authorised redirect URI — redirectUris() lists the two
 * this site uses so the admin can show them next to the upload.
 */
class GoogleOAuthApp
{
    public const SETTING_CLIENT_ID = 'google.oauth.client_id';

    public const SETTING_CLIENT_SECRET = 'google.oauth.client_secret';

    public const SETTING_PROJECT_ID = 'google.oauth.project_id';

    /** Config arrays the stored client overlays. */
    public const CONFIG_PATHS = ['services.google.business_profile', 'services.google.search_console'];

    /** Overlay the stored client onto config — once per request, from boot. */
    public static function apply(): void
    {
        if (! static::hasTable()) {
            return;
        }

        $clientId = PlatformSetting::get(self::SETTING_CLIENT_ID);
        $clientSecret = PlatformSetting::get(self::SETTING_CLIENT_SECRET);

        if (! $clientId || ! $clientSecret) {
            return;
        }

        foreach (self::CONFIG_PATHS as $path) {
            config(["{$path}.client_id" => $clientId, "{$path}.client_secret" => $clientSecret]);
        }
    }

    /** Store the client and make it live for the rest of this request. */
    public static function save(string $clientId, string $clientSecret, ?string $projectId = null): void
    {
        PlatformSetting::put(self::SETTING_CLIENT_ID, trim($clientId));
        PlatformSetting::put(self::SETTING_CLIENT_SECRET, trim($clientSecret));
        PlatformSetting::put(self::SETTING_PROJECT_ID, $projectId ? trim($projectId) : null);

        static::apply();
    }

    /** Forget the stored client; config falls back to whatever env provides. */
    public static function clear(): void
    {
        foreach ([self::SETTING_CLIENT_ID, self::SETTING_CLIENT_SECRET, self::SETTING_PROJECT_ID] as $key) {
            PlatformSetting::put($key, null);
        }

        foreach (self::CONFIG_PATHS as $path) {
            $env = $path === 'services.google.search_console'
                ? ['client_id' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_ID', env('GOOGLE_BUSINESS_PROFILE_CLIENT_ID')), 'client_secret' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET', env('GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET'))]
                : ['client_id' => env('GOOGLE_BUSINESS_PROFILE_CLIENT_ID'), 'client_secret' => env('GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET')];
            config(["{$path}.client_id" => $env['client_id'], "{$path}.client_secret" => $env['client_secret']]);
        }
    }

    /**
     * The client id, secret and project from the JSON file Google Cloud
     * Console downloads for an OAuth client ("web" for a web application,
     * "installed" for a desktop one — both carry the same two values).
     *
     * @return array{client_id: string, client_secret: string, project_id: ?string, redirect_uris: array<int, string>}
     */
    public static function parseClientJson(string $json): array
    {
        $data = json_decode($json, true);
        $client = is_array($data) ? ($data['web'] ?? $data['installed'] ?? (isset($data['client_id']) ? $data : null)) : null;

        if (! is_array($client) || empty($client['client_id']) || empty($client['client_secret'])) {
            throw ValidationException::withMessages([
                'client_json' => 'That is not a Google OAuth client file — it should be the JSON downloaded from the OAuth 2.0 Client ID in Google Cloud Console, with a client_id and client_secret inside.',
            ]);
        }

        return [
            'client_id' => (string) $client['client_id'],
            'client_secret' => (string) $client['client_secret'],
            'project_id' => isset($client['project_id']) ? (string) $client['project_id'] : null,
            'redirect_uris' => array_values(array_filter((array) ($client['redirect_uris'] ?? []), 'is_string')),
        ];
    }

    /** Where Google sends the sign-in back to — must be registered on the client. */
    public static function redirectUris(): array
    {
        return [
            'gbp' => route('admin-oauth.callback', ['provider' => 'gbp']),
            'gsc' => route('admin-oauth.callback', ['provider' => 'gsc']),
        ];
    }

    /** 'admin' when the stored client is in use, 'env' when the server's env provides one, null when neither. */
    public static function source(): ?string
    {
        if (static::hasTable() && PlatformSetting::get(self::SETTING_CLIENT_ID) && PlatformSetting::get(self::SETTING_CLIENT_SECRET)) {
            return 'admin';
        }

        return (config('services.google.business_profile.client_id') && config('services.google.business_profile.client_secret')) ? 'env' : null;
    }

    /** Presence and provenance only — never the secret, and only a hint of the id. */
    public static function status(): array
    {
        $clientId = (string) config('services.google.business_profile.client_id');
        $source = static::source();

        return [
            'configured' => $source !== null,
            'source' => $source,
            'client_id_hint' => $clientId !== '' ? static::hint($clientId) : null,
            'project_id' => static::hasTable() ? PlatformSetting::get(self::SETTING_PROJECT_ID) : null,
            'redirect_uris' => static::redirectUris(),
        ];
    }

    /** "1234567890-abc…apps.googleusercontent.com" — enough to recognise which client, not enough to reuse. */
    protected static function hint(string $clientId): string
    {
        if (strlen($clientId) <= 24) {
            return substr($clientId, 0, 6).'…';
        }

        return substr($clientId, 0, 14).'…'.substr($clientId, -28);
    }

    protected static function hasTable(): bool
    {
        try {
            return Schema::hasTable('platform_settings');
        } catch (\Throwable) {
            return false; // mid-install / migrate: env values only
        }
    }
}
