<?php

namespace App\Console\Commands;

use App\Models\OAuthToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SearchConsoleAuth extends Command
{
    public const PROVIDER = 'google_search_console';

    protected $signature = 'search-console:auth
        {--code= : Authorization code returned by Google after consent}';

    protected $description = 'OAuth flow for Google Search Console (webmasters.readonly).';

    protected const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    protected const SCOPES = 'https://www.googleapis.com/auth/webmasters.readonly';
    protected const REDIRECT_URI = 'http://127.0.0.1:8003';

    public function handle(): int
    {
        $clientId = config('services.google.search_console.client_id');
        $clientSecret = config('services.google.search_console.client_secret');

        if (! $clientId || ! $clientSecret) {
            $this->error('GOOGLE_SEARCH_CONSOLE_CLIENT_ID/SECRET must be set.');
            return self::FAILURE;
        }

        if ($code = $this->option('code')) {
            return $this->exchange($clientId, $clientSecret, (string) $code);
        }

        $url = self::AUTH_URL . '?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ]);

        $this->newLine();
        $this->info('1) Open this URL and authorize with the Google account that owns the Search Console property:');
        $this->newLine();
        $this->line($url);
        $this->newLine();
        $this->info('2) Copy the `code` parameter from the redirected URL, then run:');
        $this->line('     php artisan search-console:auth --code=PASTE_CODE_HERE');
        $this->newLine();
        $this->warn('Note: the redirect URI 127.0.0.1:8003 must be allowed on the OAuth client in Google Cloud Console.');

        return self::SUCCESS;
    }

    protected function exchange(string $clientId, string $clientSecret, string $code): int
    {
        $resp = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => self::REDIRECT_URI,
        ]);

        if (! $resp->successful()) {
            $this->error('Token exchange failed: ' . $resp->body());
            return self::FAILURE;
        }

        $data = $resp->json();
        $refresh = $data['refresh_token'] ?? null;
        if (! $refresh) {
            $this->error('No refresh_token in response. Re-run after revoking access at https://myaccount.google.com/permissions');
            return self::FAILURE;
        }

        OAuthToken::storeTokens(
            provider: self::PROVIDER,
            refreshToken: $refresh,
            accessToken: $data['access_token'] ?? null,
            expiresIn: (int) ($data['expires_in'] ?? 3600),
            scopes: explode(' ', (string) ($data['scope'] ?? self::SCOPES)),
        );

        $this->info('Stored Search Console tokens. Run: php artisan search-console:audit');
        return self::SUCCESS;
    }
}
