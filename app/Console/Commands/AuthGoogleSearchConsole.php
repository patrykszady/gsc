<?php

namespace App\Console\Commands;

use App\Services\GoogleSearchConsoleService;
use Illuminate\Console\Command;

/**
 * One-time helper: print the OAuth consent URL for Search Console and
 * exchange the resulting code for a refresh token (stored in oauth_tokens).
 *
 * Usage:
 *   php artisan seo:gsc-auth
 *   # paste the URL into a browser, sign in, copy the `code` query param
 *   # paste it back when prompted.
 */
class AuthGoogleSearchConsole extends Command
{
    protected $signature = 'seo:gsc-auth {--redirect=http://127.0.0.1:8003}';

    protected $description = 'Authorize the Google Search Console API (one-time setup)';

    public function handle(GoogleSearchConsoleService $svc): int
    {
        $clientId = config('services.google.search_console.client_id');
        if (! $clientId) {
            $this->error('GOOGLE_SEARCH_CONSOLE_CLIENT_ID (or GOOGLE_BUSINESS_PROFILE_CLIENT_ID) is not set.');
            return self::FAILURE;
        }

        $redirect = (string) $this->option('redirect');
        $this->info('1. Open this URL in a browser, sign in, and approve access:');
        $this->line('');
        $this->line($svc->getOAuthUrl($redirect));
        $this->line('');
        $this->info('2. Copy the `code` query parameter from the redirect URL.');

        $code = $this->ask('Paste the authorization code');
        if (! $code) {
            $this->error('No code provided.');
            return self::FAILURE;
        }

        $result = $svc->exchangeCodeAndStore(trim($code), $redirect);
        if (! ($result['success'] ?? false)) {
            $this->error('Exchange failed: ' . ($result['error'] ?? 'unknown'));
            return self::FAILURE;
        }

        $this->info('Refresh token stored. You can now run: php artisan seo:gsc-sync');
        return self::SUCCESS;
    }
}
