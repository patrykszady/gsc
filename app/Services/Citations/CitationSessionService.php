<?php

namespace App\Services\Citations;

use App\Models\Citation;
use App\Support\Citations\ListingPayload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One remote browser session for the citation builder: Xvfb → headed
 * Chromium driven by scripts/citations/run.mjs → x11vnc → websockify/noVNC,
 * the same stack the Yelp remote login uses (and the same display/ports,
 * so one remote session runs at a time). The Node runner keeps a state
 * file per directory that this service folds back into the citations row.
 */
class CitationSessionService
{
    public const RUNNER = 'scripts/citations/run.mjs';

    public function __construct(protected string $stateFile = '')
    {
        $this->stateFile = $stateFile ?: rtrim((string) config('citations.storage_dir', storage_path('app/citations')), '/') . '/session.json';
    }

    /** @return array{ok: bool, missing: list<string>} */
    public function checkRequirements(bool $headless = false): array
    {
        $cfg = (array) config('citations.session');
        $bins = [$cfg['node_binary'] ?? 'node'];
        if (! $headless) {
            $bins = array_merge($bins, [$cfg['xvfb_binary'], $cfg['x11vnc_binary'], $cfg['websockify_binary']]);
        }
        $missing = [];
        foreach ($bins as $bin) {
            $found = trim((string) @shell_exec('command -v ' . escapeshellarg((string) $bin) . ' 2>/dev/null'));
            if ($found === '') {
                $missing[] = (string) $bin;
            }
        }
        if (! is_file(base_path(self::RUNNER))) {
            $missing[] = self::RUNNER;
        }

        return ['ok' => $missing === [], 'missing' => $missing];
    }

    public function dirFor(Citation $citation): string
    {
        $dir = rtrim((string) config('citations.storage_dir', storage_path('app/citations')), '/') . '/' . Str::slug($citation->slug);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Start a session for one directory. Returns ['ok', 'url' (noVNC), 'expires_at', 'slug'].
     */
    public function start(Citation $citation, bool $headless = false): array
    {
        $req = $this->checkRequirements($headless);
        if (! $req['ok']) {
            return ['ok' => false, 'error' => 'Missing on this host: ' . implode(', ', $req['missing'])];
        }
        $existing = $this->readState();
        if ($existing && $this->isAlive($existing)) {
            if (($existing['slug'] ?? null) === $citation->slug) {
                return $this->buildResponse($existing);
            }

            return ['ok' => false, 'error' => 'Another citation session is running (' . ($existing['slug'] ?? '?') . '). Stop it first.'];
        }
        if ($existing) {
            $this->killState($existing);
        }

        $cfg = (array) config('citations.session');
        $dir = $this->dirFor($citation);
        $payloadFile = $dir . '/payload.json';
        $stateFile = $dir . '/state.json';
        $definition = $citation->definition();
        file_put_contents($payloadFile, json_encode([
            'directory' => ['slug' => $citation->slug] + $definition,
            'listing' => ListingPayload::make(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @unlink($stateFile);
        @unlink($dir . '/resume.flag');
        @unlink($dir . '/stop.flag');
        @mkdir($dir . '/shots', 0775, true);
        $userDataDir = (string) ($cfg['user_data_dir'] ?: storage_path('app/citations/profile'));
        @mkdir($userDataDir, 0775, true);
        $logDir = storage_path('logs');
        $chromeLog = $logDir . '/citations-runner.log';
        $display = (string) $cfg['display'];
        $maxTtl = (int) $cfg['max_ttl_seconds'];

        $this->killOrphans($cfg);

        $pids = [];
        if (! $headless) {
            $pids['xvfb'] = $this->spawn(sprintf('%s %s -screen 0 %s -ac -nolisten tcp', escapeshellarg((string) $cfg['xvfb_binary']), escapeshellarg($display), escapeshellarg((string) $cfg['screen'])), $logDir . '/citations-xvfb.log');
            if (! $pids['xvfb']) {
                return ['ok' => false, 'error' => 'Failed to start Xvfb.'];
            }
            usleep(600000);
        }

        $cmd = [
            escapeshellarg((string) ($cfg['node_binary'] ?? 'node')),
            escapeshellarg(base_path(self::RUNNER)),
            '--slug=' . escapeshellarg($citation->slug),
            '--payload=' . escapeshellarg($payloadFile),
            '--state=' . escapeshellarg($stateFile),
            '--dir=' . escapeshellarg($dir),
            '--user-data-dir=' . escapeshellarg($userDataDir),
            '--timeout-ms=' . escapeshellarg((string) ($maxTtl * 1000)),
        ];
        if ($headless) {
            $cmd[] = '--headless';
        }
        $pids['runner'] = $this->spawn(($headless ? '' : 'DISPLAY=' . escapeshellarg($display) . ' ') . implode(' ', $cmd), $chromeLog);
        usleep(800000);
        if (! $pids['runner'] || ! $this->pidAlive($pids['runner'])) {
            $this->killPids($pids);

            return ['ok' => false, 'error' => 'The browser runner exited immediately. See ' . $chromeLog];
        }

        $password = null;
        if (! $headless) {
            $vncPort = (int) $cfg['vnc_port'];
            $wsPort = (int) $cfg['ws_port'];
            $password = Str::random(8);
            $this->spawn(sprintf('env -u WAYLAND_DISPLAY -u XDG_SESSION_TYPE -u DISPLAY -u XDG_RUNTIME_DIR %s -display %s -rfbport %d -localhost -nolookup -shared -forever -bg -o %s -passwd %s -quiet',
                escapeshellarg((string) $cfg['x11vnc_binary']), escapeshellarg($display), $vncPort, escapeshellarg($logDir . '/citations-x11vnc.log'), escapeshellarg($password)), $logDir . '/citations-x11vnc.log');
            usleep(400000);
            $pids['x11vnc'] = (int) trim((string) @shell_exec('pgrep -f ' . escapeshellarg('x11vnc.*-rfbport ' . $vncPort) . ' 2>/dev/null | head -1'));
            if (! $pids['x11vnc'] || ! $this->waitPort($vncPort)) {
                $this->killPids($pids);

                return ['ok' => false, 'error' => 'x11vnc failed to start on port ' . $vncPort . '.'];
            }
            $novncWeb = $this->novncWebRoot($cfg);
            $pids['websockify'] = $this->spawn(sprintf('%s --web=%s %s:%d 127.0.0.1:%d', escapeshellarg((string) $cfg['websockify_binary']), escapeshellarg($novncWeb), escapeshellarg((string) $cfg['ws_host']), $wsPort, $vncPort), $logDir . '/citations-websockify.log');
            if (! $pids['websockify'] || ! $this->waitPort($wsPort, 50)) {
                $this->killPids($pids);

                return ['ok' => false, 'error' => 'websockify failed to start on port ' . $wsPort . '.'];
            }
        }

        $now = time();
        $state = [
            'slug' => $citation->slug, 'headless' => $headless, 'display' => $display,
            'vnc_port' => (int) $cfg['vnc_port'], 'ws_port' => (int) $cfg['ws_port'], 'ws_host' => (string) $cfg['ws_host'],
            'password' => $password, 'public_url' => $cfg['public_url'] ?? null,
            'pids' => $pids, 'dir' => $dir, 'started_at' => $now, 'expires_at' => $now + $maxTtl,
        ];
        $this->writeState($state);
        Log::info('citations: session started', ['slug' => $citation->slug, 'pids' => $pids, 'headless' => $headless]);

        return $this->buildResponse($state);
    }

    /** Session status plus the runner's own state for the active directory. */
    public function status(): array
    {
        $state = $this->readState();
        if (! $state) {
            return ['running' => false, 'slug' => null, 'runner' => null];
        }
        $alive = $this->isAlive($state);
        $runner = $this->readRunnerState((string) ($state['dir'] ?? ''));
        if (! $alive && ! ($runner['done'] ?? false) && empty($runner['error'])) {
            $runner = ($runner ?? []) + ['error' => 'The browser session ended before the run finished.'];
        }
        if (! $alive) {
            $this->killState($state);
        }

        return [
            'running' => $alive,
            'slug' => $state['slug'],
            'headless' => (bool) ($state['headless'] ?? false),
            'started_at' => $state['started_at'],
            'expires_at' => $state['expires_at'],
            'viewer' => $alive && empty($state['headless']) ? $this->buildResponse($state)['url'] : null,
            'runner' => $runner,
        ];
    }

    /** Let the runner continue after a human step. */
    public function resume(Citation $citation): bool
    {
        return (bool) @touch($this->dirFor($citation) . '/resume.flag');
    }

    public function stop(): array
    {
        $state = $this->readState();
        if ($state) {
            @touch(($state['dir'] ?? config('citations.storage_dir', storage_path('app/citations'))) . '/stop.flag');
            usleep(300000);
            $this->killState($state);
        } else {
            $this->killOrphans((array) config('citations.session'));
        }

        return ['ok' => true];
    }

    public function readRunnerState(string $dir): ?array
    {
        $file = rtrim($dir, '/') . '/state.json';
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /** Fold the runner's state into the citation row. */
    public function syncCitation(Citation $citation): Citation
    {
        $session = $this->status();
        $alive = ($session['running'] ?? false) && ($session['slug'] ?? null) === $citation->slug;
        $runner = $this->readRunnerState($this->dirFor($citation)) ?? (($session['slug'] ?? null) === $citation->slug ? $session['runner'] : null);
        if (! $runner) {
            return $citation;
        }

        foreach ((array) ($runner['log'] ?? []) as $line) {
            $key = ($line['at'] ?? '') . '|' . ($line['msg'] ?? '');
            $seen = collect($citation->log ?? [])->contains(fn ($l) => (($l['at'] ?? '') . '|' . ($l['message'] ?? '')) === $key);
            if (! $seen) {
                $citation->addLog((string) ($line['msg'] ?? ''), $line['step'] ?? null);
                $log = $citation->log;
                $log[count($log) - 1]['at'] = $line['at'] ?? now()->toDateTimeString();
                $citation->log = $log;
            }
        }
        $citation->screenshots = array_values(array_map(fn ($s) => ['file' => basename((string) ($s['file'] ?? '')), 'label' => $s['label'] ?? '', 'at' => $s['at'] ?? null], (array) ($runner['shots'] ?? [])));
        $citation->photos_uploaded = max((int) $citation->photos_uploaded, (int) ($runner['photos_uploaded'] ?? 0));
        if (! empty($runner['account']['email'])) {
            $citation->account_email = $runner['account']['email'];
        }
        if (! empty($runner['account']['password'])) {
            $citation->account_password = $runner['account']['password'];
        }
        if (! empty($runner['listing_url'])) {
            $citation->listing_url = mb_substr((string) $runner['listing_url'], 0, 500);
        }
        $citation->last_run_at = now();

        if (! empty($runner['error'])) {
            $citation->status = Citation::STATUS_FAILED;
            $citation->human_reason = null;
            $citation->note = mb_substr((string) $runner['error'], 0, 500);
        } elseif (($runner['outcome'] ?? null) === 'no_mechanism') {
            $citation->status = Citation::STATUS_NO_MECHANISM;
            $citation->note = (string) ($runner['note'] ?? 'No way to list a business was found on this site.');
        } elseif (($runner['outcome'] ?? null) === 'unreachable') {
            $citation->status = Citation::STATUS_UNREACHABLE;
            $citation->note = (string) ($runner['note'] ?? 'The site did not load.');
        } elseif (! empty($runner['done'])) {
            $needsEmail = in_array('email', (array) ($citation->definition()['needs'] ?? []), true) || in_array('account', (array) ($citation->definition()['needs'] ?? []), true);
            $citation->status = $needsEmail ? Citation::STATUS_PENDING_VERIFICATION : Citation::STATUS_SUBMITTED;
            $citation->submitted_at = $citation->submitted_at ?: now();
            $citation->human_reason = null;
            $verification = $citation->verification ?? [];
            if ($needsEmail && empty($verification['email'])) {
                $verification['email'] = 'pending';
            }
            $citation->verification = $verification;
        } elseif (! empty($runner['needs_human'])) {
            $citation->status = $alive ? Citation::STATUS_NEEDS_HUMAN : Citation::STATUS_FAILED;
            $citation->human_reason = (string) ($runner['reason'] ?? 'A human step is needed.');
            if (! $alive) {
                $citation->note = 'The session ended while waiting for a human step.';
            }
        } else {
            $citation->status = $alive ? Citation::STATUS_RUNNING : Citation::STATUS_FAILED;
            if (! $alive) {
                $citation->note = 'The browser session ended before the run finished.';
            }
        }
        $citation->save();

        return $citation;
    }

    public function tailLog(int $bytes = 4000): string
    {
        $file = storage_path('logs/citations-runner.log');
        if (! is_file($file)) {
            return '';
        }
        $size = (int) filesize($file);
        $fh = fopen($file, 'rb');
        if (! $fh) {
            return '';
        }
        fseek($fh, max(0, $size - $bytes));
        $out = (string) stream_get_contents($fh);
        fclose($fh);

        return $out;
    }

    protected function buildResponse(array $state): array
    {
        $url = null;
        if (empty($state['headless'])) {
            $publicUrl = $state['public_url'] ?: ('http://127.0.0.1:' . $state['ws_port']);
            $mountPath = trim((string) (parse_url((string) $publicUrl, PHP_URL_PATH) ?: ''), '/');
            $url = rtrim((string) $publicUrl, '/') . '/vnc.html?' . http_build_query([
                'autoconnect' => 1, 'resize' => 'scale', 'reconnect' => 1,
                'password' => $state['password'], 'path' => $mountPath !== '' ? $mountPath . '/websockify' : 'websockify',
            ]);
        }

        return ['ok' => true, 'slug' => $state['slug'], 'url' => $url, 'started_at' => $state['started_at'], 'expires_at' => $state['expires_at']];
    }

    /** noVNC web root, aliased under the public path prefix when the proxy does not strip it. */
    protected function novncWebRoot(array $cfg): string
    {
        $novncWeb = rtrim((string) $cfg['novnc_web'], '/');
        $mountPath = trim((string) (parse_url((string) ($cfg['public_url'] ?? ''), PHP_URL_PATH) ?: ''), '/');
        if ($mountPath === '' || ! is_dir($novncWeb)) {
            return $novncWeb;
        }
        $aliasRoot = rtrim((string) config('citations.storage_dir', storage_path('app/citations')), '/') . '/novnc-web';
        @mkdir($aliasRoot, 0755, true);
        $link = $aliasRoot . '/' . $mountPath;
        if (! is_link($link) || @readlink($link) !== $novncWeb) {
            if (is_link($link) || file_exists($link)) {
                @unlink($link);
            }
            @symlink($novncWeb, $link);
        }

        return $aliasRoot;
    }

    protected function killOrphans(array $cfg): void
    {
        foreach (['Xvfb ' . $cfg['display'], 'x11vnc.*-rfbport ' . (int) $cfg['vnc_port'], 'websockify.*' . (int) $cfg['ws_port'], 'citations/run.mjs'] as $pattern) {
            @shell_exec('pkill -TERM -f ' . escapeshellarg($pattern) . ' 2>/dev/null');
        }
        usleep(400000);
        foreach (['Xvfb ' . $cfg['display'], 'x11vnc.*-rfbport ' . (int) $cfg['vnc_port'], 'websockify.*' . (int) $cfg['ws_port'], 'citations/run.mjs'] as $pattern) {
            @shell_exec('pkill -KILL -f ' . escapeshellarg($pattern) . ' 2>/dev/null');
        }
        foreach ([(int) $cfg['vnc_port'], (int) $cfg['ws_port']] as $port) {
            for ($i = 0; $i < 30; $i++) {
                $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if (! $sock) {
                    break;
                }
                @fclose($sock);
                usleep(100000);
            }
        }
    }

    protected function waitPort(int $port, int $tries = 30): bool
    {
        for ($i = 0; $i < $tries; $i++) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
            if ($sock) {
                @fclose($sock);

                return true;
            }
            usleep(100000);
        }

        return false;
    }

    protected function spawn(string $cmd, string $logFile): int
    {
        $pid = (int) trim((string) @shell_exec(sprintf('nohup sh -c %s >> %s 2>&1 < /dev/null & echo $!', escapeshellarg($cmd), escapeshellarg($logFile))));

        return $pid > 0 ? $pid : 0;
    }

    protected function isAlive(array $state): bool
    {
        $runner = (int) ($state['pids']['runner'] ?? 0);
        if ($runner <= 0 || ! $this->pidAlive($runner)) {
            return false;
        }

        return time() < (int) ($state['expires_at'] ?? 0);
    }

    protected function pidAlive(int $pid): bool
    {
        return $pid > 0 && function_exists('posix_kill') ? @posix_kill($pid, 0) : (trim((string) @shell_exec('ps -p ' . (int) $pid . ' -o pid= 2>/dev/null')) !== '');
    }

    protected function killPids(array $pids): void
    {
        foreach ($pids as $pid) {
            if ((int) $pid > 0) {
                @shell_exec('kill -TERM ' . (int) $pid . ' 2>/dev/null');
            }
        }
        usleep(300000);
        foreach ($pids as $pid) {
            if ((int) $pid > 0) {
                @shell_exec('kill -KILL ' . (int) $pid . ' 2>/dev/null');
            }
        }
    }

    protected function killState(array $state): void
    {
        $this->killPids((array) ($state['pids'] ?? []));
        @unlink($this->stateFile);
    }

    protected function readState(): ?array
    {
        if (! is_file($this->stateFile)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->stateFile), true);

        return is_array($data) ? $data : null;
    }

    protected function writeState(array $state): void
    {
        @mkdir(dirname($this->stateFile), 0775, true);
        file_put_contents($this->stateFile, json_encode($state));
    }
}
