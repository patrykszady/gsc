<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Spawns an in-browser Yelp login session using:
 *   Xvfb            virtual X display
 *   yelp-login.mjs  headed Chromium on that display (Puppeteer)
 *   x11vnc          VNC server bound to the display
 *   websockify      HTTP+WS gateway serving noVNC web client
 *
 * The admin embeds the noVNC `vnc.html` page in an iframe and completes
 * captcha / 2FA visually. When the Puppeteer login flow finishes (or the
 * admin clicks Stop) the service kills every spawned process and frees ports.
 *
 * State (pids, ports, password) is persisted to storage/app/yelp-remote-login.json
 * so the Livewire poll endpoint can survive php-fpm worker restarts.
 */
class YelpRemoteLoginService
{
    protected string $stateFile;

    public function __construct()
    {
        $this->stateFile = storage_path('app/yelp-remote-login.json');
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.yelp.business.remote_login.enabled', true);
    }

    /**
     * Check whether the required host binaries are installed.
     *
     * @return array{ok:bool, missing:array<int,string>}
     */
    public function checkRequirements(): array
    {
        $cfg = config('services.yelp.business.remote_login');
        $bins = [
            'Xvfb' => $cfg['xvfb_binary'],
            'x11vnc' => $cfg['x11vnc_binary'],
            'websockify' => $cfg['websockify_binary'],
            'node' => config('services.yelp.business.node_binary', 'node'),
        ];
        $missing = [];
        foreach ($bins as $label => $bin) {
            $which = trim((string) @shell_exec('command -v ' . escapeshellarg((string) $bin) . ' 2>/dev/null'));
            if ($which === '') {
                $missing[] = $label;
            }
        }
        $novncWeb = (string) $cfg['novnc_web'];
        if (! is_file(rtrim($novncWeb, '/') . '/vnc.html') && ! is_file(rtrim($novncWeb, '/') . '/vnc_lite.html')) {
            $missing[] = 'novnc (' . $novncWeb . '/vnc.html)';
        }
        return ['ok' => $missing === [], 'missing' => $missing];
    }

    /**
     * Start a remote-login session. Idempotent: if one is already running and
     * still alive, returns its connection info instead of starting a new one.
     *
     * @return array{ok:bool, error?:string, url?:string, password?:string, started_at?:int, expires_at?:int}
     */
    public function start(): array
    {
        if (! $this->isEnabled()) {
            return ['ok' => false, 'error' => 'Remote login is disabled (services.yelp.business.remote_login.enabled).'];
        }
        $biz = app(YelpBusinessService::class);
        if (! $biz->isConfigured()) {
            return ['ok' => false, 'error' => 'Set Yelp email and password first.'];
        }

        $req = $this->checkRequirements();
        if (! $req['ok']) {
            return ['ok' => false, 'error' => 'Missing host packages: ' . implode(', ', $req['missing']) . '. Install with: sudo apt install xvfb x11vnc novnc websockify'];
        }

        // Reuse an existing live session.
        $existing = $this->readState();
        if ($existing && $this->isAlive($existing)) {
            return $this->buildResponse($existing);
        }
        if ($existing) {
            $this->killState($existing);
        }

        $cfg = config('services.yelp.business.remote_login');
        $display = (string) $cfg['display'];
        $screen = (string) $cfg['screen'];
        $vncPort = (int) $cfg['vnc_port'];
        $wsHost = (string) $cfg['ws_host'];
        $wsPort = (int) $cfg['ws_port'];
        $novncWeb = rtrim((string) $cfg['novnc_web'], '/');
        $maxTtl = (int) $cfg['max_ttl_seconds'];

        // Clean orphan processes that might be holding our display/ports.
        @shell_exec('pkill -f ' . escapeshellarg('Xvfb ' . $display) . ' 2>/dev/null');
        @shell_exec('pkill -f ' . escapeshellarg('x11vnc.*-rfbport ' . $vncPort) . ' 2>/dev/null');
        @shell_exec('pkill -f ' . escapeshellarg('websockify.*' . $wsPort) . ' 2>/dev/null');
        @shell_exec('pkill -f ' . escapeshellarg('yelp-login.mjs') . ' 2>/dev/null');
        usleep(400000);

        // Fresh profile dir so we don't reopen with stale tabs / poisoned cookies.
        $userDataDir = (string) (config('services.yelp.business.user_data_dir') ?: storage_path('app/yelp-puppeteer'));
        if (is_dir($userDataDir)) {
            @shell_exec('rm -rf ' . escapeshellarg($userDataDir));
        }
        @mkdir($userDataDir, 0775, true);

        $logDir = storage_path('logs');
        @mkdir($logDir, 0775, true);
        $xvfbLog = $logDir . '/yelp-remote-xvfb.log';
        $chromeLog = $logDir . '/yelp-remote-chrome.log';
        $vncLog = $logDir . '/yelp-remote-x11vnc.log';
        $wsLog = $logDir . '/yelp-remote-websockify.log';

        // 1) Xvfb
        $xvfbPid = $this->spawn(
            sprintf('%s %s -screen 0 %s -ac -nolisten tcp',
                escapeshellarg((string) $cfg['xvfb_binary']),
                escapeshellarg($display),
                escapeshellarg($screen)
            ),
            $xvfbLog
        );
        if (! $xvfbPid) {
            return ['ok' => false, 'error' => 'Failed to start Xvfb. See ' . $xvfbLog];
        }
        // Give Xvfb a moment to bind the display socket.
        usleep(600000);

        // 2) Headed Chromium via Puppeteer login script
        $bizCfg = config('services.yelp.business');
        $node = $bizCfg['node_binary'] ?? 'node';
        $script = base_path('scripts/yelp-login.mjs');
        $cmdParts = [
            escapeshellarg((string) $node),
            escapeshellarg($script),
            '--mode=login',
            '--user-data-dir=' . escapeshellarg($userDataDir),
            '--email=' . escapeshellarg((string) $biz->getEmail()),
            '--password=' . escapeshellarg((string) $biz->getPassword()),
            '--timeout-ms=' . escapeshellarg((string) ($maxTtl * 1000)),
        ];
        if (! empty($bizCfg['proxy'])) {
            $cmdParts[] = '--proxy=' . escapeshellarg((string) $bizCfg['proxy']);
        }
        if ($key = config('services.twocaptcha.api_key')) {
            $cmdParts[] = '--twocaptcha-key=' . escapeshellarg((string) $key);
        }
        $chromePid = $this->spawn(
            'DISPLAY=' . escapeshellarg($display) . ' ' . implode(' ', $cmdParts),
            $chromeLog
        );
        if (! $chromePid) {
            @posix_kill($xvfbPid, SIGTERM);
            return ['ok' => false, 'error' => 'Failed to start Chromium. See ' . $chromeLog];
        }

        // 3) x11vnc — random password so the noVNC URL needs the secret.
        $password = Str::random(20);
        $passwdFile = storage_path('app/yelp-remote-login.passwd');
        @file_put_contents($passwdFile, $password);
        @chmod($passwdFile, 0600);
        // Use -storepasswd-style file (`-passwdfile`) which accepts plain text.
        $vncPid = $this->spawn(
            sprintf(
                '%s -display %s -rfbport %d -localhost -nolookup -shared -forever -bg -o %s -passwdfile %s -quiet',
                escapeshellarg((string) $cfg['x11vnc_binary']),
                escapeshellarg($display),
                $vncPort,
                escapeshellarg($vncLog),
                escapeshellarg($passwdFile)
            ),
            $vncLog
        );
        // x11vnc -bg daemonizes; the pid we captured is the parent shell. Look up the real one.
        usleep(400000);
        $realVncPid = (int) trim((string) @shell_exec('pgrep -f ' . escapeshellarg('x11vnc.*-rfbport ' . $vncPort) . ' 2>/dev/null | head -1'));
        if ($realVncPid) {
            $vncPid = $realVncPid;
        }
        if (! $vncPid) {
            @posix_kill($chromePid, SIGTERM);
            @posix_kill($xvfbPid, SIGTERM);
            return ['ok' => false, 'error' => 'Failed to start x11vnc. See ' . $vncLog];
        }

        // 4) websockify
        $wsPid = $this->spawn(
            sprintf(
                '%s --web=%s %s:%d 127.0.0.1:%d',
                escapeshellarg((string) $cfg['websockify_binary']),
                escapeshellarg($novncWeb),
                escapeshellarg($wsHost),
                $wsPort,
                $vncPort
            ),
            $wsLog
        );
        if (! $wsPid) {
            @posix_kill($vncPid, SIGTERM);
            @posix_kill($chromePid, SIGTERM);
            @posix_kill($xvfbPid, SIGTERM);
            return ['ok' => false, 'error' => 'Failed to start websockify. See ' . $wsLog];
        }

        $now = time();
        $state = [
            'display' => $display,
            'vnc_port' => $vncPort,
            'ws_host' => $wsHost,
            'ws_port' => $wsPort,
            'password' => $password,
            'public_url' => $cfg['public_url'] ?? null,
            'pids' => [
                'xvfb' => $xvfbPid,
                'chrome' => $chromePid,
                'x11vnc' => $vncPid,
                'websockify' => $wsPid,
            ],
            'started_at' => $now,
            'expires_at' => $now + $maxTtl,
        ];
        $this->writeState($state);

        Log::info('Yelp remote login: session started', $state);

        return $this->buildResponse($state);
    }

    /**
     * @return array{ok:bool, running:bool, logged_in:?bool, started_at?:int, expires_at?:int}
     */
    public function status(): array
    {
        $state = $this->readState();
        if (! $state) {
            return ['ok' => true, 'running' => false, 'logged_in' => null];
        }
        $alive = $this->isAlive($state);
        if (! $alive) {
            // Chromium has exited — likely login completed (or failed). Tear down.
            $this->killState($state);
        }

        $loggedIn = null;
        // Cheap heuristic: cookie file in profile dir = a session got persisted.
        $userDataDir = (string) (config('services.yelp.business.user_data_dir') ?: storage_path('app/yelp-puppeteer'));
        $cookieDb = $userDataDir . '/Default/Cookies';
        if (is_file($cookieDb) && filesize($cookieDb) > 1024) {
            $loggedIn = true;
        }

        return [
            'ok' => true,
            'running' => $alive,
            'logged_in' => $loggedIn,
            'started_at' => $state['started_at'] ?? null,
            'expires_at' => $state['expires_at'] ?? null,
        ];
    }

    public function stop(): array
    {
        $state = $this->readState();
        if ($state) {
            $this->killState($state);
        }
        return ['ok' => true];
    }

    // ---- internals ----

    protected function buildResponse(array $state): array
    {
        $publicUrl = $state['public_url'] ?: null;
        if (! $publicUrl) {
            $scheme = request()->isSecure() ? 'https' : 'http';
            $host = request()->getHost();
            $publicUrl = $scheme . '://' . $host . ':' . $state['ws_port'];
        }
        // Use vnc_lite for smaller chrome and auto-scaling.
        $url = rtrim($publicUrl, '/') . '/vnc.html?'
            . http_build_query([
                'autoconnect' => 1,
                'resize' => 'scale',
                'reconnect' => 1,
                'password' => $state['password'],
                'path' => 'websockify',
            ]);

        return [
            'ok' => true,
            'url' => $url,
            'password' => $state['password'],
            'started_at' => $state['started_at'],
            'expires_at' => $state['expires_at'],
        ];
    }

    protected function spawn(string $cmd, string $logFile): int
    {
        $full = sprintf('nohup sh -c %s >> %s 2>&1 < /dev/null & echo $!',
            escapeshellarg($cmd),
            escapeshellarg($logFile)
        );
        $pid = (int) trim((string) @shell_exec($full));
        return $pid > 0 ? $pid : 0;
    }

    protected function isAlive(array $state): bool
    {
        $chromePid = (int) ($state['pids']['chrome'] ?? 0);
        // If Chromium (the parent puppeteer node process) is gone, the user
        // either finished login or it died — treat the session as ended.
        if (! $chromePid || ! $this->pidAlive($chromePid)) {
            return false;
        }
        if (! empty($state['expires_at']) && time() > (int) $state['expires_at']) {
            return false;
        }
        return true;
    }

    protected function pidAlive(int $pid): bool
    {
        if ($pid <= 0) return false;
        return @posix_kill($pid, 0);
    }

    protected function killState(array $state): void
    {
        foreach ((array) ($state['pids'] ?? []) as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                @posix_kill($pid, SIGTERM);
            }
        }
        // Belt-and-suspenders: kill by pattern in case pids drifted.
        @shell_exec('pkill -f ' . escapeshellarg('yelp-login.mjs') . ' 2>/dev/null');
        if (! empty($state['vnc_port'])) {
            @shell_exec('pkill -f ' . escapeshellarg('x11vnc.*-rfbport ' . $state['vnc_port']) . ' 2>/dev/null');
        }
        if (! empty($state['ws_port'])) {
            @shell_exec('pkill -f ' . escapeshellarg('websockify.*' . $state['ws_port']) . ' 2>/dev/null');
        }
        if (! empty($state['display'])) {
            @shell_exec('pkill -f ' . escapeshellarg('Xvfb ' . $state['display']) . ' 2>/dev/null');
        }
        @unlink($this->stateFile);
        @unlink(storage_path('app/yelp-remote-login.passwd'));
        Log::info('Yelp remote login: session stopped');
    }

    protected function readState(): ?array
    {
        if (! is_file($this->stateFile)) return null;
        $json = @file_get_contents($this->stateFile);
        if (! is_string($json) || $json === '') return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    protected function writeState(array $state): void
    {
        @file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
        @chmod($this->stateFile, 0600);
    }
}
