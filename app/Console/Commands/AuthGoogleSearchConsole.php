<?php

namespace App\Console\Commands;

use App\Services\GoogleSearchConsoleService;
use Illuminate\Console\Command;

/**
 * One-time helper: drive the OAuth consent flow for Search Console and
 * persist the resulting refresh token to oauth_tokens.
 *
 * Default (recommended): spins up a tiny loopback HTTP server on
 * 127.0.0.1:<port> and auto-captures the `code` after the user approves.
 *
 *   php artisan seo:gsc-auth
 *
 * Manual mode (for headless servers, or when the port is unavailable):
 *
 *   php artisan seo:gsc-auth --manual
 *
 * Requires the same redirect URI to be registered in the Google Cloud
 * OAuth client. Default is http://127.0.0.1:8003. Override with --port.
 */
class AuthGoogleSearchConsole extends Command
{
    protected $signature = 'seo:gsc-auth
        {--port=8003 : Loopback port for the OAuth callback}
        {--manual : Skip the loopback listener; paste the code manually}
        {--no-open : Do not attempt to launch a browser}';

    protected $description = 'Authorize the Google Search Console API (one-time setup)';

    public function handle(GoogleSearchConsoleService $svc): int
    {
        $clientId = config('services.google.search_console.client_id');
        if (! $clientId) {
            $this->error('GOOGLE_SEARCH_CONSOLE_CLIENT_ID (or GOOGLE_BUSINESS_PROFILE_CLIENT_ID) is not set.');
            return self::FAILURE;
        }

        $port = max(1024, (int) $this->option('port'));
        $redirect = "http://127.0.0.1:{$port}";
        $url = $svc->getOAuthUrl($redirect);

        $this->info('Authorize URL:');
        $this->line('');
        $this->line($url);
        $this->line('');
        $this->warn("Make sure {$redirect} is registered as an Authorized redirect URI in the Google Cloud OAuth client.");
        $this->line('');

        if ($this->option('manual')) {
            return $this->runManual($svc, $redirect);
        }

        return $this->runLoopback($svc, $redirect, $port, $url);
    }

    protected function runManual(GoogleSearchConsoleService $svc, string $redirect): int
    {
        $this->info('Open the URL above in any browser, approve access, then copy the `code` query parameter from the redirected URL bar.');

        $code = $this->ask('Paste the authorization code');
        if (! $code) {
            $this->error('No code provided.');
            return self::FAILURE;
        }

        return $this->finalize($svc, trim($code), $redirect);
    }

    protected function runLoopback(GoogleSearchConsoleService $svc, string $redirect, int $port, string $url): int
    {
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        if (! $sock) {
            $this->error("Cannot bind 127.0.0.1:{$port} ({$errstr}). Re-run with --manual or pick a free --port=NNNN.");
            return self::FAILURE;
        }
        stream_set_timeout($sock, 300); // 5 minutes for the user to approve

        if (! $this->option('no-open')) {
            $this->tryOpenBrowser($url);
        }

        $this->info("Waiting for Google to redirect to {$redirect} … (Ctrl+C to abort)");

        $client = @stream_socket_accept($sock, 300);
        if (! $client) {
            @fclose($sock);
            $this->error('Timed out waiting for the OAuth callback (5 minutes).');
            return self::FAILURE;
        }

        $request = '';
        $deadline = microtime(true) + 5;
        while (! feof($client) && microtime(true) < $deadline) {
            $chunk = @fread($client, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $request .= $chunk;
            if (str_contains($request, "\r\n\r\n")) {
                break;
            }
        }

        $code = null;
        $error = null;
        if (preg_match('#^GET\s+(\S+)\s+HTTP/#', $request, $m)) {
            $query = parse_url($m[1], PHP_URL_QUERY) ?: '';
            parse_str($query, $params);
            $code = $params['code'] ?? null;
            $error = $params['error'] ?? null;
        }

        $bodyOk = '<!doctype html><meta charset="utf-8"><title>GSC authorized</title>'
            . '<style>body{font-family:system-ui,sans-serif;padding:40px;color:#0f172a}'
            . 'h1{color:#16a34a}</style>'
            . '<h1>✓ Authorized</h1><p>You can close this tab and return to the terminal.</p>';
        $bodyErr = '<!doctype html><meta charset="utf-8"><title>GSC auth failed</title>'
            . '<style>body{font-family:system-ui,sans-serif;padding:40px;color:#0f172a}'
            . 'h1{color:#dc2626}</style>'
            . '<h1>✗ Authorization failed</h1><p>See terminal output for details.</p>';

        $body = ($code && ! $error) ? $bodyOk : $bodyErr;
        $resp = "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
        @fwrite($client, $resp);
        @fclose($client);
        @fclose($sock);

        if ($error) {
            $this->error("OAuth error from Google: {$error}");
            return self::FAILURE;
        }
        if (! $code) {
            $this->error('No authorization code in callback. Request was: ' . substr($request, 0, 200));
            return self::FAILURE;
        }

        $this->info('Got authorization code, exchanging for tokens…');
        return $this->finalize($svc, $code, $redirect);
    }

    protected function finalize(GoogleSearchConsoleService $svc, string $code, string $redirect): int
    {
        $result = $svc->exchangeCodeAndStore($code, $redirect);
        if (! ($result['success'] ?? false)) {
            $this->error('Exchange failed: ' . ($result['error'] ?? 'unknown'));
            return self::FAILURE;
        }
        $this->info('✓ Refresh token stored. Next: php artisan seo:gsc-sync');
        return self::SUCCESS;
    }

    protected function tryOpenBrowser(string $url): void
    {
        $cmd = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start ""',
            default => 'xdg-open',
        };
        // Best-effort, ignore failures (e.g. on a headless server).
        @exec(sprintf('%s %s > /dev/null 2>&1 &', $cmd, escapeshellarg($url)));
    }
}
