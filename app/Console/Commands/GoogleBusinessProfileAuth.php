<?php

namespace App\Console\Commands;

use App\Services\GoogleBusinessProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use SsSystems\Platform\Google\Contracts\TokenStore;
use SsSystems\Platform\Google\OAuthClient;
use SsSystems\Platform\Google\StoredGrant;

/**
 * The manual, copy-the-code way to authorize Business Profile from a
 * terminal. It signs in through the ONE shared Google client (kit 0.14.0's
 * OAuthClient — GOOGLE_OAUTH_CLIENT_ID/_SECRET, or the old
 * GOOGLE_BUSINESS_PROFILE_CLIENT_* pair while that is still the fallback)
 * and stores the grant through the kit's TokenStore, which records the
 * client that issued it. The Platforms screen's Connect button is the
 * normal way; this is the fallback when the web flow is unavailable.
 */
class GoogleBusinessProfileAuth extends Command
{
    protected $signature = 'google-business-profile:auth
        {--refresh : Exchange an authorization code for a refresh token}
        {--code= : The authorization code from the OAuth consent screen}';

    protected $description = 'Authenticate with Google Business Profile OAuth2. Generates the authorization URL and exchanges the code for a refresh token.';

    protected const SCOPES = 'https://www.googleapis.com/auth/business.manage';

    protected const REDIRECT_URI = 'http://127.0.0.1:8003';

    public function handle(OAuthClient $oauth): int
    {
        if (! $oauth->isConfigured()) {
            $this->error('The shared Google sign-in client is not set: GOOGLE_OAUTH_CLIENT_ID and GOOGLE_OAUTH_CLIENT_SECRET must be in .env');

            return self::FAILURE;
        }

        if ($this->option('refresh') || $this->option('code')) {
            return $this->exchangeCode($oauth);
        }

        return $this->showAuthUrl($oauth);
    }

    protected function showAuthUrl(OAuthClient $oauth): int
    {
        $url = $oauth->authorizeUrlWithState(self::REDIRECT_URI, [self::SCOPES], null);

        $this->newLine();
        $this->info('Step 1: Open this URL in your browser and sign in with the Google account that owns the Business Profile:');
        $this->newLine();
        $this->line($url);
        $this->newLine();
        $this->info('Step 2: After authorizing, Google will show you an authorization code.');
        $this->info('Step 3: Run this command again with the code:');
        $this->newLine();
        $this->line('  php artisan google-business-profile:auth --code=PASTE_CODE_HERE');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function exchangeCode(OAuthClient $oauth): int
    {
        $code = $this->option('code') ?: $this->ask('Paste the authorization code from Google');

        if (empty($code)) {
            $this->error('No authorization code provided.');

            return self::FAILURE;
        }

        $this->info('Exchanging authorization code for refresh token...');

        $result = $oauth->exchangeCode($code, self::REDIRECT_URI, fetchEmail: false);

        if (! $result['ok']) {
            $this->error('Token exchange failed: '.($result['error_description'] ?? $result['error'] ?? 'Google did not say why.'));

            // Common issue: redirect_uri mismatch
            if (($result['error'] ?? '') === 'redirect_uri_mismatch') {
                $this->newLine();
                $this->warn('Make sure your OAuth client in Google Cloud Console has this redirect URI:');
                $this->line('  '.self::REDIRECT_URI);
                $this->newLine();
                $this->warn('If your client is a "Web application" type, you may need to use a "Desktop" type client instead,');
                $this->warn('or add "http://localhost" as an authorized redirect URI and update REDIRECT_URI in this command.');
            }

            return self::FAILURE;
        }

        $refreshToken = $result['refresh_token'];

        if (! $refreshToken) {
            $this->error('No refresh token in response. Try adding prompt=consent to force a new refresh token.');

            return self::FAILURE;
        }

        // Stored (encrypted, for the current site) through the kit's
        // TokenStore, with the scopes Google granted and the issuing client.
        app(TokenStore::class)->put(GoogleBusinessProfileService::PROVIDER, new StoredGrant(
            refreshToken: $refreshToken,
            accessToken: $result['access_token'],
            accessTokenExpiresAt: $result['access_token'] !== null ? Carbon::now()->addSeconds(max(0, $result['expires_in'] - 120)) : null,
            scopes: $result['scopes'] ?? [],
            clientId: $oauth->clientId(),
        ));

        $this->newLine();
        $this->info('Success! Tokens stored in the database (encrypted).');
        $this->newLine();
        $this->info('Optionally, you can also add this to your .env as a backup:');
        $this->newLine();
        $this->line("GOOGLE_BUSINESS_PROFILE_REFRESH_TOKEN=\"{$refreshToken}\"");
        $this->newLine();

        $this->info('Then run:');
        $this->line('  php artisan google-business-profile:locations');
        $this->newLine();

        return self::SUCCESS;
    }
}
