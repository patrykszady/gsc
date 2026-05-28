<?php

namespace App\Console\Commands\Concerns;

use App\Console\Commands\SearchConsoleAuth;
use App\Models\OAuthToken;
use Illuminate\Support\Facades\Http;

/**
 * Shared Google Search Console API plumbing: refresh the OAuth access token and resolve the
 * configured site URL into both the API-expected `siteUrl` and a browser-style base URL.
 *
 * Used by every `seo:gsc-*` command so we keep one canonical path for token refresh and one
 * canonical interpretation of `seo.search_console.site_url` (which may be `sc-domain:` or a
 * full URL prefix).
 */
trait UsesSearchConsoleApi
{
    /**
     * Refresh and return a Search Console access token, or null if OAuth is not set up.
     */
    protected function gscAccessToken(): ?string
    {
        $row = OAuthToken::forProvider(SearchConsoleAuth::PROVIDER);
        if (! $row || ! $row->refresh_token) {
            $this->error('No Search Console OAuth token. Run: php artisan seo:gsc-auth');
            return null;
        }
        if ($row->hasValidAccessToken()) {
            return $row->access_token;
        }

        $resp = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.business_profile.client_id'),
            'client_secret' => config('services.google.business_profile.client_secret'),
            'refresh_token' => $row->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        if (! $resp->successful()) {
            $this->error('Token refresh failed: ' . $resp->body());
            return null;
        }
        $d = $resp->json();
        $row->access_token = $d['access_token'] ?? null;
        $row->access_token_expires_at = now()->addSeconds(((int) ($d['expires_in'] ?? 3600)) - 120);
        $row->save();

        return $row->access_token;
    }

    /**
     * The siteUrl value to pass into Search Console API calls. May be `sc-domain:gs.construction`
     * or a fully qualified URL prefix — pass through verbatim.
     */
    protected function gscSiteUrl(?string $override = null): string
    {
        return (string) ($override ?: config('seo.search_console.site_url'));
    }

    /**
     * Browser-style base URL derived from the siteUrl, used for building per-URL probes.
     * Strips the `sc-domain:` prefix and assumes https.
     */
    protected function gscBaseUrl(?string $override = null): string
    {
        $site = $this->gscSiteUrl($override);
        return str_starts_with($site, 'sc-domain:')
            ? 'https://' . substr($site, strlen('sc-domain:'))
            : rtrim($site, '/');
    }
}
