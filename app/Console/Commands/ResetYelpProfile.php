<?php

namespace App\Console\Commands;

use App\Services\YelpBusinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ResetYelpProfile extends Command
{
    protected $signature = 'yelp:reset-profile
                            {--keep-proxy : Do not rotate the IPRoyal sticky-session token in .env}
                            {--no-restart : Do not signal Horizon to terminate workers}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Wipe burned Yelp Chromium profile (cookies + datadome + sessions), rotate proxy sticky-session token, and restart queue workers. Use when DataDome has flagged the exit IP and/or device fingerprint.';

    public function handle(YelpBusinessService $service): int
    {
        if (! $this->option('force') && ! $this->confirm('This wipes Yelp cookies/localStorage/sessions and rotates the proxy token. Continue?', true)) {
            $this->warn('Aborted.');
            return self::SUCCESS;
        }

        $userDataDir = (string) (config('services.yelp.business.user_data_dir') ?: '');
        if ($userDataDir === '' || ! is_dir($userDataDir)) {
            $this->warn("user_data_dir not found: {$userDataDir}");
        } else {
            $this->wipeProfile($userDataDir);
        }

        if (! $this->option('keep-proxy')) {
            $this->rotateProxyToken();
        }

        // Clear sticky session-dead banner + automation lock so the next
        // login attempt has a clean slate.
        Cache::forget('yelp.session_dead');
        Cache::forget('yelp:browser-automation:lock');
        Cache::forget('yelp:browser-automation:current');
        $this->info('Cleared yelp.session_dead + automation lock caches.');

        if (! $this->option('no-restart')) {
            try {
                Artisan::call('horizon:terminate');
                $this->info('Horizon terminate signal sent (Supervisor will restart workers).');
            } catch (\Throwable $e) {
                $this->warn('horizon:terminate failed: ' . $e->getMessage());
            }
        }

        Log::channel('yelp')->info('Yelp profile reset via artisan command', [
            'wiped_profile' => is_dir($userDataDir),
            'rotated_proxy' => ! $this->option('keep-proxy'),
            'restarted_horizon' => ! $this->option('no-restart'),
        ]);

        $this->info('');
        $this->info('Done. Next steps:');
        $this->line('  1. Open /admin/platforms');
        $this->line('  2. Click "Verify Login" — fresh proxy IP + fresh profile should bypass DataDome.');
        $this->line('  3. After auth, retry your upload job.');

        return self::SUCCESS;
    }

    protected function wipeProfile(string $dir): void
    {
        $default = $dir . '/Default';
        // Targets: cookie jar, all storage tiers DataDome fingerprints, and
        // the tab-restore session files (already handled by yelp-login.mjs
        // but redundant deletion here makes the reset bullet-proof).
        $targets = [
            $default . '/Cookies',
            $default . '/Cookies-journal',
            $default . '/Local Storage',
            $default . '/Session Storage',
            $default . '/IndexedDB',
            $default . '/Service Worker',
            $default . '/Sessions',
            $default . '/Current Session',
            $default . '/Current Tabs',
            $default . '/Last Session',
            $default . '/Last Tabs',
            $default . '/Network',
            // Cached DataDome JS challenge artifacts
            $default . '/Cache',
            $default . '/Code Cache',
        ];
        foreach ($targets as $t) {
            if (! file_exists($t)) continue;
            $ok = is_dir($t) ? $this->rrmdir($t) : @unlink($t);
            $this->line(($ok ? '  wiped: ' : '  FAIL : ') . $t);
        }
    }

    protected function rrmdir(string $dir): bool
    {
        if (! is_dir($dir)) return false;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        return @rmdir($dir);
    }

    protected function rotateProxyToken(): void
    {
        $envPath = base_path('.env');
        if (! is_file($envPath) || ! is_writable($envPath)) {
            $this->warn(".env not writable at {$envPath}; skipping proxy rotation.");
            return;
        }
        $env = file_get_contents($envPath);
        if ($env === false) {
            $this->warn('Could not read .env');
            return;
        }
        $newToken = 'Reset' . date('YmdHis') . random_int(100, 999);
        $count = 0;
        // Matches both IPRoyal (`_session-XYZ`) and Bright Data (`-session-XYZ`)
        // username-embedded rotation tokens. The leading `[_-]` is captured
        // so we preserve the provider's delimiter convention.
        $env = preg_replace(
            '/([_-])session-[A-Za-z0-9]+/',
            '${1}session-' . $newToken,
            $env,
            -1,
            $count
        );
        if ($count > 0 && is_string($env)) {
            file_put_contents($envPath, $env);
            $this->info("Rotated {$count} _session-* token(s) in .env -> _session-{$newToken}");
            try {
                Artisan::call('config:clear');
                $this->info('config:clear OK');
            } catch (\Throwable $e) {
                $this->warn('config:clear failed: ' . $e->getMessage());
            }
        } else {
            $this->warn('No _session-* token found in .env (proxy URL may not use IPRoyal sticky sessions).');
        }
    }
}
