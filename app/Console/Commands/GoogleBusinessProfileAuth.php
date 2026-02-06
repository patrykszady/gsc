<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class GoogleBusinessProfileAuth extends Command
{
    protected $signature = 'google-business-profile:auth
        {--refresh : Exchange an authorization code for a refresh token}
        {--code= : The authorization code from the OAuth consent screen}';

    protected $description = 'Authenticate with Google Business Profile OAuth2. Generates the authorization URL and exchanges the code for a refresh token.';

    protected const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    protected const SCOPES = 'https://www.googleapis.com/auth/business.manage';
    protected const REDIRECT_URI = 'http://127.0.0.1:8003';

    public function handle(): int
    {
        $clientId = config('services.google.business_profile.client_id');
        $clientSecret = config('services.google.business_profile.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            $this->error('GOOGLE_BUSINESS_PROFILE_CLIENT_ID and _CLIENT_SECRET must be set in .env');

            return self::FAILURE;
        }

        if ($this->option('refresh') || $this->option('code')) {
            return $this->exchangeCode($clientId, $clientSecret);
        }

        return $this->showAuthUrl($clientId);
    }

    protected function showAuthUrl(string $clientId): int
    {
        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);

        $url = self::AUTH_URL . '?' . $params;

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

    protected function exchangeCode(string $clientId, string $clientSecret): int
    {
        $code = $this->option('code') ?: $this->ask('Paste the authorization code from Google');

        if (empty($code)) {
            $this->error('No authorization code provided.');

            return self::FAILURE;
        }

        $this->info('Exchanging authorization code for refresh token...');

        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => self::REDIRECT_URI,
        ]);

        if (! $response->successful()) {
            $error = $response->json();
            $this->error('Token exchange failed: ' . ($error['error_description'] ?? $response->body()));

            // Common issue: redirect_uri mismatch
            if (($error['error'] ?? '') === 'redirect_uri_mismatch') {
                $this->newLine();
                $this->warn('Make sure your OAuth client in Google Cloud Console has this redirect URI:');
                $this->line('  ' . self::REDIRECT_URI);
                $this->newLine();
                $this->warn('If your client is a "Web application" type, you may need to use a "Desktop" type client instead,');
                $this->warn('or add "http://localhost" as an authorized redirect URI and update REDIRECT_URI in this command.');
            }

            return self::FAILURE;
        }

        $data = $response->json();
        $refreshToken = $data['refresh_token'] ?? null;

        if (! $refreshToken) {
            $this->error('No refresh token in response. Try adding prompt=consent to force a new refresh token.');
            $this->line('Response: ' . json_encode($data, JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Success! Add this to your .env file:');
        $this->newLine();
        $this->line("GOOGLE_BUSINESS_PROFILE_REFRESH_TOKEN=\"{$refreshToken}\"");
        $this->newLine();

        $this->info('Then run:');
        $this->line('  php artisan google-business-profile:locations');
        $this->newLine();

        return self::SUCCESS;
    }
}
