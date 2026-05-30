<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Spawns an in-browser Instagram login session using:
 *   Xvfb               virtual X display
 *   instagram-login.mjs  headed Chromium on that display (Puppeteer)
 *   x11vnc             VNC server bound to the display
 *   websockify         HTTP+WS gateway serving noVNC web client
 *
 * Mirrors YelpRemoteLoginService but on a separate display + ports so the two
 * remote viewers can coexist. The Puppeteer login script auto-detects a
 * successful login and exits cleanly; we tear the support stack down when
 * Chromium exits and surface the captured outcome JSON to the admin.
 */
class InstagramRemoteLoginService
{
    protected string $stateFile;

    public function __construct()
    {
        $this->stateFile = storage_path('app/instagram-remote-login.json');
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.instagram.remote_login.enabled', true);
    }

    /**
     * @return array{ok:bool, missing:array<int,string>}
     */
    public function checkRequirements(): array
    {
        $cfg = config('services.instagram.remote_login');
        $bins = [
            'Xvfb' => $cfg['xvfb_binary'],
            'x11vnc' => $cfg['x11vnc_binary'],
            'websockify' => $cfg['websockify_binary'],
            'node' => config('services.instagram.node_binary', 'node'),
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
     * Headlessly probe the persisted profile for a live IG session. Returns
     * true=logged in, false=not logged in, null=unable to determine.
     */
    public function checkSession(int $timeoutSeconds = 45): ?bool
    {
        $node = (string) (config('services.instagram.node_binary', 'node'));
        $script = base_path('scripts/instagram-check-session.mjs');
        $userDataDir = $this->userDataDir();
        if (! is_dir($userDataDir)) {
            return false;
        }
        $cmd = sprintf(
            '%s %s --user-data-dir=%s --timeout-ms=%d 2>/dev/null',
            escapeshellarg($node),
            escapeshellarg($script),
            escapeshellarg($userDataDir),
            $timeoutSeconds * 1000
        );
        $output = (string) @shell_exec('timeout ' . ($timeoutSeconds + 5) . ' ' . $cmd);
        $output = trim($output);
        if ($output === '') return null;
        // Take the last JSON line.
        $lines = preg_split('/\r?\n/', $output) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '' || $line[0] !== '{') continue;
            $data = json_decode($line, true);
            if (is_array($data) && array_key_exists('authenticated', $data)) {
                return (bool) $data['authenticated'];
            }
        }
        return null;
    }

    /**
     * @return array{ok:bool, error?:string, url?:string, password?:string, started_at?:int, expires_at?:int}
     */
    public function start(bool $resetProfile = false): array
    {
        if (! $this->isEnabled()) {
            return ['ok' => false, 'error' => 'Instagram remote login is disabled.'];
        }

        $req = $this->checkRequirements();
        if (! $req['ok']) {
            Log::warning('Instagram remote login: missing host packages', ['missing' => $req['missing']]);
            return ['ok' => false, 'error' => 'Missing host packages: ' . implode(', ', $req['missing']) . '. Install with: sudo apt install xvfb x11vnc novnc websockify'];
        }

        $existing = $this->readState();
        if ($existing && $this->isAlive($existing)) {
            return $this->buildResponse($existing);
        }
        if ($existing) {
            $this->killState($existing);
        }

        $cfg = config('services.instagram.remote_login');
        $display = (string) $cfg['display'];
        $screen = (string) $cfg['screen'];
        $vncPort = (int) $cfg['vnc_port'];
        $wsHost = (string) $cfg['ws_host'];
        $wsPort = (int) $cfg['ws_port'];
        $novncWeb = rtrim((string) $cfg['novnc_web'], '/');
        $maxTtl = (int) $cfg['max_ttl_seconds'];

        // Clear any orphan processes holding our display/ports.
        $killPatterns = [
            'Xvfb ' . $display,
            'x11vnc.*-rfbport ' . $vncPort,
            'websockify.*' . $wsPort,
            'instagram-login.mjs',
        ];
        foreach ($killPatterns as $pat) {
            @shell_exec('pkill -TERM -f ' . escapeshellarg($pat) . ' 2>/dev/null');
        }
        usleep(400000);
        foreach ($killPatterns as $pat) {
            @shell_exec('pkill -KILL -f ' . escapeshellarg($pat) . ' 2>/dev/null');
        }
        $this->waitForPortFree('127.0.0.1', $vncPort, 3.0);
        $this->waitForPortFree('127.0.0.1', $wsPort, 3.0);

        $userDataDir = $this->userDataDir();
        if ($resetProfile && is_dir($userDataDir)) {
            Log::info('Instagram remote login: wiping user-data-dir', ['dir' => $userDataDir]);
            @shell_exec('rm -rf ' . escapeshellarg($userDataDir));
        }
        @mkdir($userDataDir, 0775, true);

        $logDir = storage_path('logs');
        @mkdir($logDir, 0775, true);
        $xvfbLog = $logDir . '/instagram-remote-xvfb.log';
        $chromeLog = $logDir . '/instagram-remote-chrome.log';
        $vncLog = $logDir . '/instagram-remote-x11vnc.log';
        $wsLog = $logDir . '/instagram-remote-websockify.log';

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
        usleep(600000);

        // 2) Chromium via instagram-login.mjs
        $node = (string) (config('services.instagram.node_binary', 'node'));
        $script = base_path('scripts/instagram-login.mjs');
        $cmdParts = [
            escapeshellarg($node),
            escapeshellarg($script),
            '--user-data-dir=' . escapeshellarg($userDataDir),
            '--timeout-ms=' . escapeshellarg((string) ($maxTtl * 1000)),
        ];
        $chromePid = $this->spawn(
            'DISPLAY=' . escapeshellarg($display) . ' ' . implode(' ', $cmdParts),
            $chromeLog
        );
        if (! $chromePid) {
            @posix_kill($xvfbPid, SIGTERM);
            return ['ok' => false, 'error' => 'Failed to start Chromium. See ' . $chromeLog];
        }
        usleep(800000);
        if (! $this->pidAlive($chromePid)) {
            @posix_kill($xvfbPid, SIGTERM);
            return ['ok' => false, 'error' => 'Chromium exited immediately. See ' . $chromeLog];
        }

        // 3) x11vnc
        $password = Str::random(8);
        $vncPid = $this->spawn(
            sprintf(
                'env -u WAYLAND_DISPLAY -u XDG_SESSION_TYPE %s -display %s -rfbport %d -localhost -nolookup -shared -forever -bg -o %s -passwd %s -quiet',
                escapeshellarg((string) $cfg['x11vnc_binary']),
                escapeshellarg($display),
                $vncPort,
                escapeshellarg($vncLog),
                escapeshellarg($password)
            ),
            $vncLog
        );
        usleep(400000);
        $realVncPid = (int) trim((string) @shell_exec('pgrep -f ' . escapeshellarg('x11vnc.*-rfbport ' . $vncPort) . ' 2>/dev/null | head -1'));
        if ($realVncPid) {
            $vncPid = $realVncPid;
        }
        if (! $vncPid || ! $this->waitForPortBound('127.0.0.1', $vncPort, 3.0)) {
            @posix_kill($vncPid ?: 0, SIGKILL);
            @posix_kill($chromePid, SIGTERM);
            @posix_kill($xvfbPid, SIGTERM);
            return ['ok' => false, 'error' => 'x11vnc failed to bind port ' . $vncPort . '. See ' . $vncLog];
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
        if (! $wsPid || ! $this->waitForPortBound('127.0.0.1', $wsPort, 5.0)) {
            @posix_kill($wsPid ?: 0, SIGTERM);
            @posix_kill($vncPid, SIGTERM);
            @posix_kill($chromePid, SIGTERM);
            @posix_kill($xvfbPid, SIGTERM);
            return ['ok' => false, 'error' => 'websockify failed to bind port ' . $wsPort . '. See ' . $wsLog];
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

        Log::info('Instagram remote login: session started', $state);

        return $this->buildResponse($state);
    }

    /**
     * @return array{ok:bool, running:bool, started_at?:int, expires_at?:int}
     */
    public function status(): array
    {
        $state = $this->readState();
        if (! $state) {
            return ['ok' => true, 'running' => false];
        }
        $alive = $this->isAlive($state);
        if (! $alive) {
            Log::info('Instagram remote login: chromium exited, tearing down', [
                'ran_seconds' => isset($state['started_at']) ? time() - (int) $state['started_at'] : null,
            ]);
            $this->killState($state);
        } else {
            // Verify support stack.
            foreach (['xvfb', 'x11vnc', 'websockify'] as $proc) {
                $pid = (int) ($state['pids'][$proc] ?? 0);
                if ($pid > 0 && ! $this->pidAlive($pid)) {
                    Log::warning('Instagram remote login: support proc died', ['proc' => $proc, 'pid' => $pid]);
                    $this->killState($state);
                    $alive = false;
                    break;
                }
            }
        }

        return [
            'ok' => true,
            'running' => $alive,
            'started_at' => $state['started_at'] ?? null,
            'expires_at' => $state['expires_at'] ?? null,
        ];
    }

    public function stop(): array
    {
        $state = $this->readState();
        if ($state) {
            $this->killState($state);
        } else {
            $this->killOrphans();
        }
        return ['ok' => true];
    }

    public function tailChromeLog(int $bytes = 6000): string
    {
        return $this->tailLog(storage_path('logs/instagram-remote-chrome.log'), $bytes);
    }

    /**
     * Parse the chrome log to recover the script's final outcome JSON line.
     */
    public function readLoginOutcome(): ?array
    {
        $log = storage_path('logs/instagram-remote-chrome.log');
        if (! is_file($log)) return null;
        $tail = $this->tailLog($log, 8000);
        if ($tail === '' || $tail === '(empty)' || $tail === '(unable to read)') return null;
        $lines = preg_split('/\r?\n/', $tail) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '' || $line[0] !== '{') continue;
            $data = json_decode($line, true);
            if (is_array($data) && array_key_exists('ok', $data)) {
                return $data;
            }
        }
        return null;
    }

    public function userDataDir(): string
    {
        return (string) (config('services.instagram.user_data_dir')
            ?: storage_path('app/instagram-puppeteer'));
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

        $publicPath = (string) (parse_url($publicUrl, PHP_URL_PATH) ?: '');
        $mountPath = trim($publicPath, '/');
        $wsPath = $mountPath !== '' ? $mountPath . '/websockify' : 'websockify';

        $url = rtrim($publicUrl, '/') . '/vnc.html?'
            . http_build_query([
                'autoconnect' => 1,
                'resize' => 'scale',
                'reconnect' => 1,
                'password' => $state['password'],
                'path' => $wsPath,
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

    protected function tailLog(string $file, int $bytes = 4000): string
    {
        if (! is_file($file)) return '(log file does not exist)';
        $size = (int) @filesize($file);
        if ($size <= 0) return '(empty)';
        $fh = @fopen($file, 'rb');
        if (! $fh) return '(unable to read)';
        $offset = max(0, $size - $bytes);
        @fseek($fh, $offset);
        $tail = (string) @fread($fh, $bytes);
        @fclose($fh);
        return trim($tail);
    }

    protected function isAlive(array $state): bool
    {
        $chromePid = (int) ($state['pids']['chrome'] ?? 0);
        if (! $chromePid || ! $this->pidAlive($chromePid)) return false;
        if (! empty($state['expires_at']) && time() > (int) $state['expires_at']) return false;
        return true;
    }

    protected function pidAlive(int $pid): bool
    {
        if ($pid <= 0) return false;
        return @posix_kill($pid, 0);
    }

    protected function waitForPortBound(string $host, int $port, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $errno = 0; $errstr = '';
            $sock = @fsockopen($host, $port, $errno, $errstr, 0.3);
            if ($sock) {
                @fclose($sock);
                return true;
            }
            usleep(100000);
        }
        return false;
    }

    protected function waitForPortFree(string $host, int $port, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $errno = 0; $errstr = '';
            $sock = @fsockopen($host, $port, $errno, $errstr, 0.2);
            if (! $sock) return;
            @fclose($sock);
            usleep(100000);
        }
    }

    protected function killState(array $state): void
    {
        foreach ((array) ($state['pids'] ?? []) as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) @posix_kill($pid, SIGTERM);
        }
        @shell_exec('pkill -f ' . escapeshellarg('instagram-login.mjs') . ' 2>/dev/null');
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
        Log::info('Instagram remote login: session stopped');
    }

    protected function killOrphans(): void
    {
        $cfg = config('services.instagram.remote_login');
        $display = (string) ($cfg['display'] ?? ':98');
        $vncPort = (int) ($cfg['vnc_port'] ?? 5998);
        $wsPort = (int) ($cfg['ws_port'] ?? 6081);
        @shell_exec('pkill -f ' . escapeshellarg('instagram-login.mjs') . ' 2>/dev/null');
        @shell_exec('pkill -f ' . escapeshellarg('x11vnc.*-rfbport ' . $vncPort) . ' 2>/dev/null');
        @shell_exec('pkill -f ' . escapeshellarg('websockify.*' . $wsPort) . ' 2>/dev/null');
        @shell_exec('pkill -f ' . escapeshellarg('Xvfb ' . $display) . ' 2>/dev/null');
        @unlink($this->stateFile);
        Log::info('Instagram remote login: orphan cleanup completed');
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
