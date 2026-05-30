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
     * Pass $resetProfile=true to wipe the persistent Chromium profile before
     * launch. Default is to PRESERVE the profile so DataDome cookies, login
     * progress, and 2captcha solves carry across Verify Login clicks — wiping
     * on every attempt is what makes the proxy IP look like a bot and earns
     * the 429 Too Many Requests we keep seeing in the wild.
     *
     * @return array{ok:bool, error?:string, url?:string, password?:string, started_at?:int, expires_at?:int}
     */
    public function start(bool $resetProfile = false): array
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
            Log::channel('yelp')->error('Yelp remote login: missing host packages', [
                'missing' => $req['missing'],
            ]);
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

        // When the iframe is reached through a path-prefixed reverse proxy
        // (e.g. https://dev.gs.construction/yelp-vnc), cloudflared/nginx do
        // NOT strip the prefix before forwarding to websockify. Without help,
        // websockify looks up `/yelp-vnc/vnc.html` in `/usr/share/novnc/` and
        // 404s on every static asset. Build a small writable web root that
        // contains the prefix as a symlink to the real noVNC tree, and point
        // websockify at it. WS upgrades are path-agnostic so this only
        // affects the static HTML/JS/CSS lookup.
        $publicUrlPath = (string) (parse_url((string) ($cfg['public_url'] ?? ''), PHP_URL_PATH) ?: '');
        $mountPath = trim($publicUrlPath, '/');
        if ($mountPath !== '' && is_dir($novncWeb)) {
            $aliasRoot = storage_path('app/yelp-novnc-web');
            if (! is_dir($aliasRoot)) @mkdir($aliasRoot, 0755, true);
            $aliasLink = $aliasRoot . '/' . $mountPath;
            if (! is_link($aliasLink) || @readlink($aliasLink) !== $novncWeb) {
                if (is_link($aliasLink) || file_exists($aliasLink)) @unlink($aliasLink);
                @symlink($novncWeb, $aliasLink);
            }
            $novncWeb = $aliasRoot;
        }

        // Clean orphan processes that might be holding our display/ports.
        // Send SIGTERM first then SIGKILL — old x11vnc instances can hang on to
        // port 5999 across restarts, causing the new VNC server to fail and the
        // browser to talk to a stale server with the old password.
        $killPatterns = [
            'Xvfb ' . $display,
            'x11vnc.*-rfbport ' . $vncPort,
            'websockify.*' . $wsPort,
            'yelp-login.mjs',
        ];
        foreach ($killPatterns as $pat) {
            @shell_exec('pkill -TERM -f ' . escapeshellarg($pat) . ' 2>/dev/null');
        }
        usleep(400000);
        foreach ($killPatterns as $pat) {
            @shell_exec('pkill -KILL -f ' . escapeshellarg($pat) . ' 2>/dev/null');
        }
        // Wait up to 3s for the VNC port to become free.
        for ($i = 0; $i < 30; $i++) {
            $errno = 0; $errstr = '';
            $sock = @fsockopen('127.0.0.1', $vncPort, $errno, $errstr, 0.2);
            if (! $sock) {
                break;
            }
            @fclose($sock);
            usleep(100000);
        }
        // Wait up to 3s for the websockify port to become free too. Without
        // this, Reset Profile re-spawns websockify while the old instance is
        // still holding port 6080 in TIME_WAIT / actively bound, and the new
        // iframe ends up talking to a dead websockify (or fails to bind at
        // all and nginx returns 502 "failed to connect to the new server").
        for ($i = 0; $i < 30; $i++) {
            $errno = 0; $errstr = '';
            $sock = @fsockopen('127.0.0.1', $wsPort, $errno, $errstr, 0.2);
            if (! $sock) {
                break;
            }
            @fclose($sock);
            usleep(100000);
        }

        // Persistent Chromium profile dir. We do NOT wipe by default: the
        // operator may have spent 2captcha credits getting past DataDome on
        // the previous attempt, and throwing away those cookies forces a
        // fresh challenge plus a hot proxy IP -> 429 spiral. Wipe only when
        // explicitly requested (Reset Profile button) or if the dir doesn't
        // exist yet.
        $userDataDir = (string) (config('services.yelp.business.user_data_dir') ?: storage_path('app/yelp-puppeteer'));
        if ($resetProfile && is_dir($userDataDir)) {
            Log::channel('yelp')->info('Yelp remote login: wiping user-data-dir (resetProfile=true)', [
                'dir' => $userDataDir,
            ]);
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
            Log::channel('yelp')->error('Yelp remote login: Xvfb spawn failed', [
                'log' => $xvfbLog,
                'tail' => $this->tailLog($xvfbLog),
            ]);
            return ['ok' => false, 'error' => 'Failed to start Xvfb. See ' . $xvfbLog];
        }
        // Give Xvfb a moment to bind the display socket.
        usleep(600000);

        // 2) Headed Chromium via Puppeteer login script
        $bizCfg = config('services.yelp.business');
        $node = $bizCfg['node_binary'] ?? 'node';
        $script = base_path('scripts/yelp-login.mjs');
        $email = (string) $biz->getEmail();
        $password = (string) $biz->getPassword();

        // Surface what credentials we are about to hand to puppeteer-core
        // WITHOUT leaking the actual password. A length + 6-char sha256 prefix
        // is enough for the operator to detect "wrong password" stored in the
        // DB (e.g. accidental whitespace, last char dropped, autofill leaked
        // the email into the password slot, etc.).
        Log::channel('yelp')->info('Yelp remote login: launching script with credentials', [
            'email' => $email,
            'email_len' => strlen($email),
            'password_len' => strlen($password),
            'password_fp' => $password !== '' ? substr(hash('sha256', $password), 0, 6) : null,
            'password_has_leading_space' => $password !== '' && ctype_space($password[0]),
            'password_has_trailing_space' => $password !== '' && ctype_space($password[strlen($password) - 1]),
        ]);

        $cmdParts = [
            escapeshellarg((string) $node),
            escapeshellarg($script),
            '--mode=login',
            '--user-data-dir=' . escapeshellarg($userDataDir),
            '--email=' . escapeshellarg($email),
            '--password=' . escapeshellarg($password),
            '--timeout-ms=' . escapeshellarg((string) ($maxTtl * 1000)),
        ];
        // Cookie injection: when a cookies file is present we skip the
        // proxy entirely. Cookies are bound by Yelp to the IP that issued
        // them; pairing them with a fresh proxy IP raises DataDome's
        // suspicion. Connecting direct from this host (where the operator
        // also runs their personal browser) keeps the IP fingerprint
        // consistent with the cookies.
        $cookiesFile = storage_path('app/yelp-cookies.json');
        $hasCookies = is_file($cookiesFile) && filesize($cookiesFile) > 0;
        if ($hasCookies) {
            $cmdParts[] = '--cookies-file=' . escapeshellarg($cookiesFile);
        }
        if (! $hasCookies && ! empty($bizCfg['proxy'])) {
            // Force a unique exit IP on every login attempt so a
            // DataDome-blocked IP is replaced automatically — no .env edit
            // needed. Supports IPRoyal (`_session-XYZ_lifetime-...`) and
            // Bright Data (`-session-XYZ` appended to the username).
            $proxyUrl = $this->forceUniqueProxySession((string) $bizCfg['proxy'], 'Yelp');
            $cmdParts[] = '--proxy=' . escapeshellarg($proxyUrl);
        }
        if ($key = config('services.twocaptcha.api_key')) {
            $cmdParts[] = '--twocaptcha-key=' . escapeshellarg((string) $key);
        }
        $chromePid = $this->spawn(
            'DISPLAY=' . escapeshellarg($display) . ' ' . implode(' ', $cmdParts),
            $chromeLog
        );
        if (! $chromePid) {
            Log::channel('yelp')->error('Yelp remote login: Chromium spawn failed', [
                'log' => $chromeLog,
                'tail' => $this->tailLog($chromeLog),
            ]);
            @posix_kill($xvfbPid, SIGTERM);
            return ['ok' => false, 'error' => 'Failed to start Chromium. See ' . $chromeLog];
        }
        // Give Chromium a moment to actually start (it might crash on launch
        // due to missing libs, bad proxy URL, or a corrupt user-data dir).
        // Confirm the puppeteer process is still alive — if not, surface the
        // log tail rather than silently proceeding to x11vnc.
        usleep(800000);
        if (! $this->pidAlive($chromePid)) {
            Log::channel('yelp')->error('Yelp remote login: Chromium exited immediately after launch', [
                'pid' => $chromePid,
                'log' => $chromeLog,
                'tail' => $this->tailLog($chromeLog),
            ]);
            @posix_kill($xvfbPid, SIGTERM);
            return ['ok' => false, 'error' => 'Chromium exited immediately. See ' . $chromeLog];
        }

        // 3) x11vnc — random password. The legacy VNC auth (security type 2)
        // truncates to 8 bytes, so generate exactly 8 alphanumeric chars to
        // avoid any URL-encoding / truncation ambiguity between the noVNC
        // iframe param and what x11vnc actually compares against.
        //
        // We pass the password via -passwd (NOT -passwdfile) because some
        // x11vnc builds treat -passwdfile as an obfuscated `-storepasswd`
        // file and reject plain text with "wrong password" on every connect.
        // Localhost-only process => visibility in `ps` is acceptable.
        $password = Str::random(8);
        // Belt-and-suspenders: clean up any stale plaintext file from prior
        // versions so we don't leave secrets lying around.
        @unlink(storage_path('app/yelp-remote-login.passwd'));
        // x11vnc 0.9.16 inspects the host session env and refuses to run
        // ("Wayland sessions are as of now only supported via -rawfb...
        // Exiting.") when WAYLAND_DISPLAY / XDG_SESSION_TYPE=wayland are
        // present, even though we explicitly target an Xvfb display. Strip
        // those vars (plus the host's DISPLAY) before exec'ing x11vnc.
        $vncPid = $this->spawn(
            sprintf(
                'env -u WAYLAND_DISPLAY -u XDG_SESSION_TYPE -u DISPLAY -u XDG_RUNTIME_DIR %s -display %s -rfbport %d -localhost -nolookup -shared -forever -bg -o %s -passwd %s -quiet',
                escapeshellarg((string) $cfg['x11vnc_binary']),
                escapeshellarg($display),
                $vncPort,
                escapeshellarg($vncLog),
                escapeshellarg($password)
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
            Log::channel('yelp')->error('Yelp remote login: x11vnc spawn failed', [
                'log' => $vncLog,
                'tail' => $this->tailLog($vncLog),
            ]);
            @posix_kill($chromePid, SIGTERM);
            @posix_kill($xvfbPid, SIGTERM);
            return ['ok' => false, 'error' => 'Failed to start x11vnc. See ' . $vncLog];
        }
        // Verify the new x11vnc is actually listening (binding to the port can
        // fail silently when a stale instance is still around).
        $vncBound = false;
        for ($i = 0; $i < 30; $i++) {
            $errno = 0; $errstr = '';
            $sock = @fsockopen('127.0.0.1', $vncPort, $errno, $errstr, 0.2);
            if ($sock) {
                @fclose($sock);
                $vncBound = true;
                break;
            }
            usleep(100000);
        }
        if (! $vncBound) {
            Log::channel('yelp')->warning('Yelp remote login: x11vnc never bound port', [
                'port' => $vncPort,
                'vnc_log' => $vncLog,
                'tail' => $this->tailLog($vncLog),
            ]);
            @posix_kill($vncPid, SIGKILL);
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
        if (! $wsPid) {
            Log::channel('yelp')->error('Yelp remote login: websockify spawn failed', [
                'log' => $wsLog,
                'tail' => $this->tailLog($wsLog),
            ]);
            @posix_kill($vncPid, SIGTERM);
            @posix_kill($chromePid, SIGTERM);
            @posix_kill($xvfbPid, SIGTERM);
            return ['ok' => false, 'error' => 'Failed to start websockify. See ' . $wsLog];
        }

        // Wait for websockify to actually accept TCP connections. Without this
        // the iframe (which loads immediately with autoconnect=1) races against
        // websockify's bind and nginx returns 502, which our client-side
        // reporter then mistakes for a permanent failure and tears down.
        $bound = false;
        for ($i = 0; $i < 50; $i++) { // up to ~5s
            $errno = 0; $errstr = '';
            $sock = @fsockopen('127.0.0.1', $wsPort, $errno, $errstr, 0.5);
            if ($sock) {
                @fclose($sock);
                $bound = true;
                break;
            }
            usleep(100000); // 100ms
        }
        if (! $bound) {
            Log::channel('yelp')->warning('Yelp remote login: websockify never bound port', [
                'port' => $wsPort,
                'ws_log' => $wsLog,
                'tail' => $this->tailLog($wsLog),
            ]);
            @posix_kill($wsPid, SIGTERM);
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

        Log::channel('yelp')->info('Yelp remote login: session started', $state + [
            // Fingerprint the VNC password so we can confirm noVNC iframe
            // and x11vnc are using the same secret without leaking it.
            'vnc_password_len' => strlen($password),
            'vnc_password_fp' => substr(hash('sha256', $password), 0, 6),
        ]);

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
            $chromeLog = storage_path('logs/yelp-remote-chrome.log');
            Log::channel('yelp')->info('Yelp remote login: chromium exited, tearing down', [
                'pids' => $state['pids'] ?? [],
                'started_at' => $state['started_at'] ?? null,
                'ran_seconds' => isset($state['started_at']) ? time() - (int) $state['started_at'] : null,
                'chrome_log_tail' => $this->tailLog($chromeLog, 2000),
            ]);
            $this->killState($state);
        } else {
            // Chromium is alive — but check that the support stack is too.
            $deadProcs = [];
            foreach (['xvfb', 'x11vnc', 'websockify'] as $proc) {
                $pid = (int) ($state['pids'][$proc] ?? 0);
                if ($pid > 0 && ! $this->pidAlive($pid)) {
                    $deadProcs[] = "$proc(pid=$pid)";
                }
            }
            if ($deadProcs) {
                Log::channel('yelp')->warning('Yelp remote login: support process(es) died', [
                    'dead' => $deadProcs,
                    'state' => $state,
                ]);
                // Tear it all down so the viewer doesn't stay in a broken 502 state.
                $this->killState($state);
                $alive = false;
            } else {
                // All PIDs alive — verify the websockify port is actually accepting
                // connections. A common failure: websockify spawned but failed to
                // bind (port already in use, missing python module), which leaves
                // nginx returning 502 to the iframe.
                $wsPort = (int) ($state['ws_port'] ?? 6080);
                $errno = 0; $errstr = '';
                $sock = @fsockopen('127.0.0.1', $wsPort, $errno, $errstr, 1.0);
                if (! $sock) {
                    Log::channel('yelp')->warning('Yelp remote login: websockify port unreachable', [
                        'port' => $wsPort,
                        'errno' => $errno,
                        'errstr' => $errstr,
                        'pids' => $state['pids'] ?? [],
                    ]);
                    $this->killState($state);
                    $alive = false;
                } else {
                    @fclose($sock);
                }
            }
        }

        $loggedIn = null;
        // Cheap heuristic: cookie file in profile dir = a session got persisted.
        $userDataDir = (string) (config('services.yelp.business.user_data_dir') ?: storage_path('app/yelp-puppeteer'));
        $cookieDb = $userDataDir . '/Default/Cookies';
        if (is_file($cookieDb) && filesize($cookieDb) > 1024) {
            $loggedIn = true;
        }

        // Only log the heuristic result when the session has actually ended,
        // so we leave a breadcrumb ("login finished, cookies persisted: yes/no")
        // without spamming on every iframe poll.
        if (! $alive) {
            Log::channel('yelp')->info('Yelp remote login: post-teardown state', [
                'logged_in_heuristic' => $loggedIn,
                'cookie_db_exists' => is_file($cookieDb),
                'cookie_db_size' => is_file($cookieDb) ? @filesize($cookieDb) : null,
            ]);
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
        } else {
            // No state file (e.g. stale from a previous deploy) — still kill
            // any orphaned processes by pattern and remove leftover files.
            $this->killOrphans();
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

        // noVNC needs a websocket path that matches the reverse-proxy mount
        // point. Example: public_url=https://gs.construction/yelp-vnc =>
        // path=yelp-vnc/websockify (not just "websockify").
        $publicPath = (string) (parse_url($publicUrl, PHP_URL_PATH) ?: '');
        $mountPath = trim($publicPath, '/');
        $wsPath = $mountPath !== '' ? $mountPath . '/websockify' : 'websockify';

        // Use vnc_lite for smaller chrome and auto-scaling.
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

    /**
     * Public accessor for the live tail of the headed Chromium's stderr log
     * (every navigation, console message, DataDome detection, etc.). Polled
     * by the admin viewer so the operator can see what the embedded browser
     * is doing in real time.
     */
    public function tailChromeLog(int $bytes = 6000): string
    {
        return $this->tailLog(storage_path('logs/yelp-remote-chrome.log'), $bytes);
    }

    /**
     * Parse the chrome log to recover the script's final outcome JSON line
     * (`{"ok":true,"authenticated":true,...}`). The login script emits this
     * to stdout right before `process.exit(0)`, so it's the most reliable
     * signal that the operator actually completed login — more trustworthy
     * than re-probing biz.yelp.com headlessly (which triggers a fresh
     * DataDome challenge unrelated to the cookies we just acquired).
     *
     * Returns null when no outcome line is present (script crashed,
     * timed out, or still running).
     */
    public function readLoginOutcome(): ?array
    {
        $log = storage_path('logs/yelp-remote-chrome.log');
        if (! is_file($log)) return null;
        $tail = $this->tailLog($log, 8000);
        if ($tail === '' || $tail === '(empty)' || $tail === '(unable to read)') {
            return null;
        }
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

    /**
     * Force a fresh upstream session token on a proxy URL so the next request
     * gets a new exit IP. Handles both providers we use:
     *   IPRoyal:    user_session-OLD_lifetime-30m  →  user_session-NEW_lifetime-30m
     *   Bright Data brd-customer-X-zone-Y-session-OLD → -session-NEW (or appended)
     */
    protected function forceUniqueProxySession(string $proxyUrl, string $tag = 'GSC'): string
    {
        $token = $tag . time() . random_int(100, 999);
        $rotated = preg_replace(
            '/([_-])session-[A-Za-z0-9]+(_lifetime-[^:@\s]+)?/',
            '${1}session-' . $token . '$2',
            $proxyUrl,
            1,
            $count
        );
        if ($count > 0 && is_string($rotated)) {
            return $rotated;
        }
        // No session marker present — leave the URL untouched. Providers like
        // IPRoyal expect sessions in the *password* (`_session-X_lifetime-...`),
        // so blindly injecting `-session-X` into the username breaks auth and
        // surfaces as ERR_TUNNEL_CONNECTION_FAILED in Chromium. Without a
        // marker the gateway already rotates per request, which is what we want.
        return $proxyUrl;
    }

    /**
     * Read the last few KB of a spawn log file so it can be embedded in the
     * yelp log channel context. Helps the admin see *why* a process failed
     * without having to SSH into the server.
     */
    protected function tailLog(string $file, int $bytes = 4000): string
    {
        if (! is_file($file)) {
            return '(log file does not exist)';
        }
        $size = (int) @filesize($file);
        if ($size <= 0) {
            return '(empty)';
        }
        $fh = @fopen($file, 'rb');
        if (! $fh) {
            return '(unable to read)';
        }
        $offset = max(0, $size - $bytes);
        @fseek($fh, $offset);
        $tail = (string) @fread($fh, $bytes);
        @fclose($fh);
        return trim($tail);
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
        Log::channel('yelp')->info('Yelp remote login: session stopped');
    }

    protected function killOrphans(): void
    {
        @shell_exec('pkill -f ' . escapeshellarg('yelp-login.mjs') . ' 2>/dev/null');
        @shell_exec('pkill -f "x11vnc.*-rfbport" 2>/dev/null');
        @shell_exec('pkill -f "websockify" 2>/dev/null');
        @shell_exec('pkill -f "Xvfb :99" 2>/dev/null');
        @unlink($this->stateFile);
        @unlink(storage_path('app/yelp-remote-login.passwd'));
        Log::channel('yelp')->info('Yelp remote login: orphan cleanup completed');
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
