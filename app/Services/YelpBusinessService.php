<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\ProjectImage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use App\Exceptions\YelpUploadThrottledException;
use App\Exceptions\YelpSessionExpiredException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Uploads project photos to a Yelp Portfolio Project on biz.yelp.com.
 *
 * NOTE: Yelp does not expose a public photo-upload API to non-partner accounts.
 * This service drives a headless Chromium session via a Node script. This is
 * unofficial, brittle against UI changes on biz.yelp.com, and may violate
 * Yelp's Terms of Service. Use at your own risk.
 */
class YelpBusinessService
{
    public const SETTING_EMAIL = 'yelp_biz_email';
    public const SETTING_PASSWORD = 'yelp_biz_password';
    private const AUTOMATION_LOCK_KEY = 'yelp:browser-automation:lock';
    private const LAST_RUN_KEY = 'yelp:browser-automation:last-run-at';
    // Host-wide cooldown: epoch seconds until which NO automation may run.
    // Set when the script signals a throttle (e.g. photos_page_oops). Lets
    // other pending jobs short-circuit BEFORE launching Chromium, which
    // otherwise wastes ~5s per job and hammers the box pointlessly.
    private const OOPS_COOLDOWN_KEY = 'yelp:browser-automation:cooldown-until';
    // Counter of consecutive throttle signals from the uploader script.
    // Reset on a successful upload; used to escalate the cooldown when
    // Yelp's /biz_photos page is persistently broken so we stop hammering.
    private const OOPS_STREAK_KEY = 'yelp:browser-automation:oops-streak';
    // Live status published by withAutomationLock for the operator-facing
    // sync command's progress bar. JSON: {operation, image_id, started_at}.
    private const CURRENT_OP_KEY = 'yelp:browser-automation:current';

    /**
     * Cache key flagging an in-flight upload attempt. Set BEFORE the Chromium
     * subprocess is spawned and cleared only on the success path. If the
     * parent PHP process dies between Yelp accepting the photo and our DB
     * write, this marker survives and tells the next retry to NOT re-upload.
     */
    public static function inFlightCacheKey(int $imageId): string
    {
        return 'yelp_biz_upload_in_flight:' . $imageId;
    }

    /**
     * Environment for Node/Chromium subprocesses.
     * Ensures fontconfig/cache paths are writable under forge/deploy users.
     *
     * @return array<string, string>
     */
    protected function browserProcessEnv(): array
    {
        $runtimeHome = storage_path('app/yelp-runtime');
        $cacheHome = $runtimeHome . '/.cache';
        $fontCache = $cacheHome . '/fontconfig';

        @mkdir($runtimeHome, 0775, true);
        @mkdir($cacheHome, 0775, true);
        @mkdir($fontCache, 0775, true);

        // Puppeteer's Chrome download lives under the REAL user $HOME
        // (e.g. /home/forge/.cache/puppeteer), placed there by
        // `npx puppeteer browsers install chrome` at deploy time. We must
        // pin PUPPETEER_CACHE_DIR explicitly because we override HOME
        // below to a per-release writable path that has no browser.
        $realHome = getenv('HOME') ?: '/home/forge';
        $puppeteerCache = config('services.yelp.business.puppeteer_cache_dir')
            ?: ($realHome . '/.cache/puppeteer');

        return [
            'HOME' => $runtimeHome,
            'XDG_CACHE_HOME' => $cacheHome,
            'XDG_CONFIG_HOME' => $runtimeHome . '/.config',
            'FONTCONFIG_PATH' => '/etc/fonts',
            'FC_CACHEDIR' => $fontCache,
            'PUPPETEER_CACHE_DIR' => $puppeteerCache,
            // Pass the userDataDir to the bash wrapper so its hard-timeout
            // cleanup can `pkill` any Chromium tree that escapes the
            // process-group SIGKILL (e.g. detached helper procs).
            'YELP_USER_DATA_DIR' => (string) (config('services.yelp.business.user_data_dir') ?: ''),
            // Keep app env explicit for subprocess logs/behavior.
            'APP_ENV' => (string) config('app.env', 'production'),
        ];
    }

    /**
     * Wrap a Node command with the OS-level flock script so two Yelp
     * Chromium processes can NEVER run at the same time — even across
     * deploy releases, queue workers, or stale code paths.
     *
     * @param  array<int, string>  $args
     * @return array<int, string>
     */
    protected function wrapWithFlock(array $args): array
    {
        $wrapper = base_path('scripts/yelp-run-locked.sh');
        if (! is_file($wrapper)) {
            return $args;
        }
        if (! is_executable($wrapper)) {
            @chmod($wrapper, 0775);
        }

        return array_merge(['/bin/bash', $wrapper], $args);
    }

    public function getEmail(): ?string
    {
        return PlatformSetting::get(self::SETTING_EMAIL, config('services.yelp.business.email'));
    }

    public function getPassword(): ?string
    {
        return PlatformSetting::get(self::SETTING_PASSWORD, config('services.yelp.business.password'));
    }

    public function isConfigured(): bool
    {
        return ! empty($this->getEmail()) && ! empty($this->getPassword());
    }

    /**
     * Spawn a headed Chromium so the user can complete login / 2FA / captcha
     * interactively. Process is detached via shell — returns immediately.
     */
    public function launchLoginBrowser(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $cfg = config('services.yelp.business');
        $script = base_path('scripts/yelp-login.mjs');
        $userDataDir = $cfg['user_data_dir'] ?? storage_path('app/yelp-puppeteer');

        // Always start fresh: kill any existing yelp-login Chromium and wipe
        // the profile so we don't reopen with stale tabs / poisoned cookies.
        @shell_exec('pkill -f ' . escapeshellarg('yelp-login.mjs') . ' 2>/dev/null');
        @shell_exec('pkill -f ' . escapeshellarg($userDataDir) . ' 2>/dev/null');
        usleep(500000);
        if (is_dir($userDataDir)) {
            @shell_exec('rm -rf ' . escapeshellarg($userDataDir));
        }
        @mkdir($userDataDir, 0775, true);

        $logFile = storage_path('logs/yelp-login-browser.log');
        $node = $cfg['node_binary'] ?? 'node';

        // Probe DISPLAY / XAUTHORITY. The web server typically runs without
        // them, so we try to discover the active X session of the same user.
        $display = $this->detectDisplay();
        $xauth = $this->detectXauthority();
        $envParts = [];
        if ($display) {
            $envParts[] = 'DISPLAY=' . escapeshellarg($display);
        }
        if ($xauth) {
            $envParts[] = 'XAUTHORITY=' . escapeshellarg($xauth);
        }
        $envPrefix = $envParts ? implode(' ', $envParts) . ' ' : '';

        $cmdParts = [
            escapeshellarg($node),
            escapeshellarg($script),
            '--mode=login',
            '--user-data-dir=' . escapeshellarg($userDataDir),
            '--email=' . escapeshellarg((string) $this->getEmail()),
            '--password=' . escapeshellarg((string) $this->getPassword()),
            '--timeout-ms=600000',
        ];
        if (! empty($cfg['proxy'])) {
            $cmdParts[] = '--proxy=' . escapeshellarg((string) $cfg['proxy']);
        }
        if ($key = config('services.twocaptcha.api_key')) {
            $cmdParts[] = '--twocaptcha-key=' . escapeshellarg((string) $key);
        }

        // Fully detach so the HTTP request returns immediately and the
        // browser keeps running independently of php-fpm.
        $cmd = sprintf(
            '%snohup %s >> %s 2>&1 < /dev/null & echo $!',
            $envPrefix,
            implode(' ', $cmdParts),
            escapeshellarg($logFile)
        );

        Log::channel('yelp')->info('Yelp: launching headed login browser', [
            'cmd' => $cmd,
            'display' => $display,
            'xauthority' => $xauth,
        ]);

        $pid = trim((string) shell_exec($cmd));
        if ($pid === '' || ! ctype_digit($pid)) {
            Log::channel('yelp')->error('Yelp: login browser launch returned no PID', ['pid' => $pid]);
            return false;
        }

        Log::channel('yelp')->info('Yelp: headed login browser launched', ['pid' => $pid, 'log' => $logFile]);
        return true;
    }

    protected function detectDisplay(): ?string
    {
        if ($d = getenv('DISPLAY')) {
            return $d;
        }
        // Try to find an active X session for the current OS user.
        $user = trim((string) shell_exec('id -un'));
        if ($user === '') {
            return null;
        }
        $out = shell_exec("ps -u " . escapeshellarg($user) . " -o command= 2>/dev/null | grep -oE 'DISPLAY=:[0-9]+' | head -n1");
        if ($out) {
            return trim(str_replace('DISPLAY=', '', $out));
        }
        // Fallbacks: most desktops use :0 or :1.
        foreach ([':0', ':1'] as $candidate) {
            if (file_exists('/tmp/.X11-unix/X' . substr($candidate, 1))) {
                return $candidate;
            }
        }
        return null;
    }

    protected function detectXauthority(): ?string
    {
        if ($x = getenv('XAUTHORITY')) {
            return $x;
        }
        $home = getenv('HOME') ?: trim((string) shell_exec('getent passwd "$(id -un)" | cut -d: -f6'));
        if ($home && file_exists($home . '/.Xauthority')) {
            return $home . '/.Xauthority';
        }
        return null;
    }

    /**
     * Fast best-effort check (no subprocess, no network):
     *   1. Cached result from a previous real check (≤ 6h old).
     *   2. Recent successful upload (cookie file touched within 30 days
     *      AND a Chromium "Cookies" sqlite exists for the profile).
     * Returns null when we genuinely don't know.
     */
    public function quickCheckSession(): ?bool
    {
        $cached = Cache::get('yelp.last_auth');
        if ($cached !== null) {
            return (bool) $cached;
        }

        $cfg = config('services.yelp.business');
        $userDataDir = $cfg['user_data_dir'] ?? storage_path('app/yelp-puppeteer');
        $cookiesFile = $userDataDir . '/Default/Cookies';
        if (is_file($cookiesFile)) {
            // If the cookies db was touched within the last 30 days, treat
            // the session as good. Yelp sessions live well past that.
            $age = time() - (int) filemtime($cookiesFile);
            if ($age < 60 * 60 * 24 * 30) {
                return true;
            }
        }
        return null;
    }

    /**
     * Mark the session as authenticated. Called after any operation that
     * proves the cookies still work (upload, login success, manual check).
     */
    public function markSessionFresh(): void
    {
        Cache::put('yelp.last_auth', true, now()->addHours(6));
        Cache::forget('yelp.session_dead');
    }

    /**
     * Mark the session as expired. Called when an unattended automation
     * detects the persistent profile is no longer logged in. Surfaces a
     * sticky flag the admin UI uses to nag the user to re-login.
     */
    public function markSessionDead(?string $note = null): void
    {
        Cache::put('yelp.last_auth', false, now()->addHours(6));
        // Sticky banner TTL is intentionally short (12h, not days). The
        // detection heuristic in scripts/yelp-upload-business-photo.mjs can
        // false-positive on a transient redirect race (bare "/" hasn't
        // resolved to "/home/<bizId>/" yet when networkidle2 fires). A long
        // TTL turns a brief race into a multi-day nag in /admin/platforms.
        // The next successful upload calls markSessionFresh() and clears the
        // flag anyway; if the session really is dead, the next failed job
        // re-sets it within minutes.
        Cache::put('yelp.session_dead', [
            'at' => now()->toIso8601String(),
            'note' => $note,
        ], now()->addHours(12));
    }

    /**
     * Slow headless check: launches a headless Chromium and visits
     * biz.yelp.com. Returns null if it could not determine.
     */
    public function checkSession(): ?bool
    {
        $cfg = config('services.yelp.business');
        $script = base_path('scripts/yelp-login.mjs');
        $userDataDir = $cfg['user_data_dir'] ?? storage_path('app/yelp-puppeteer');

        if (! is_dir($userDataDir)) {
            return false;
        }

        $args = [
            $cfg['node_binary'] ?? 'node',
            $script,
            '--mode=check',
            '--user-data-dir=' . $userDataDir,
        ];
        if (! empty($cfg['proxy'])) {
            $args[] = '--proxy=' . $cfg['proxy'];
        }

        $process = new Process($args, base_path());
        $process->setTimeout(90);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            Log::channel('yelp')->warning('Yelp: checkSession timed out');
            return null;
        } catch (ProcessSignaledException $e) {
            Log::channel('yelp')->warning('Yelp: checkSession subprocess killed', [
                'signal' => $e->getSignal(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 500),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::channel('yelp')->warning('Yelp: checkSession failed', ['error' => $e->getMessage()]);
            return null;
        }

        $payload = json_decode($this->lastJsonLine(trim($process->getOutput())) ?: '', true);
        if (! is_array($payload) || empty($payload['ok'])) {
            return null;
        }
        $authed = (bool) ($payload['authenticated'] ?? false);
        Cache::put('yelp.last_auth', $authed, now()->addHours(6));
        // Keep the sticky session_dead banner in sync with reality. Without
        // this, a successful Verify Login leaves yelp.session_dead set from
        // an earlier failed upload, and every subsequent job aborts with
        // "session expired" until the 12h TTL elapses.
        if ($authed) {
            Cache::forget('yelp.session_dead');
            Log::channel('yelp')->info('Yelp: checkSession authed=true, cleared session_dead flag');
        } else {
            $this->markSessionDead('checkSession reported not authenticated');
        }
        return $authed;
    }

    /**
     * Upload a single ProjectImage to its project's Yelp Portfolio Project.
     * Returns the Yelp photo identifier on success, or null on failure.
     */
    public function uploadProjectImage(ProjectImage $image): ?string
    {
        $project = $image->project;
        if (! $project || empty($project->yelp_portfolio_url)) {
            Log::channel('yelp')->info('Yelp: skipping upload - project has no yelp_portfolio_url', [
                'image_id' => $image->id,
                'project_id' => $project?->id,
            ]);
            return null;
        }

        $absolutePath = $this->resolveAbsolutePath($image);
        if (! $absolutePath) {
            Log::channel('yelp')->warning('Yelp: source image not found on disk', [
                'image_id' => $image->id,
                'path' => $image->path,
            ]);
            return null;
        }

        $cfg = config('services.yelp.business');
        $script = base_path('scripts/yelp-upload-portfolio-photo.mjs');

        $caption = $this->buildCaption($image);

        $args = [
            $cfg['node_binary'] ?? 'node',
            $script,
            '--portfolio-url=' . $project->yelp_portfolio_url,
            '--photo=' . $absolutePath,
            '--caption=' . $caption,
            '--user-data-dir=' . ($cfg['user_data_dir'] ?? storage_path('app/yelp-puppeteer')),
            '--email=' . $this->getEmail(),
            '--password=' . $this->getPassword(),
            '--timeout-ms=' . (int) ($cfg['timeout_ms'] ?? 180000),
        ];

        if (! empty($cfg['headed'])) {
            $args[] = '--headed';
        }
        if (! empty($cfg['proxy'])) {
            $args[] = '--proxy=' . $cfg['proxy'];
        }
        if ($key = config('services.twocaptcha.api_key')) {
            $args[] = '--twocaptcha-key=' . $key;
        }

        return $this->withAutomationLock(
            operation: 'portfolio_upload',
            callback: function () use ($args, $cfg, $image): ?string {
                $timeoutSec = ((int) ($cfg['timeout_ms'] ?? 180000)) / 1000 + 30;
                $process = new Process($this->wrapWithFlock($args), base_path());
                $process->setTimeout($timeoutSec);
                $process->setEnv($this->browserProcessEnv() + [
                    'YELP_RUN_TIMEOUT' => (string) (int) ($timeoutSec - 10),
                    'YELP_RUN_LOCK_WAIT' => (string) max(0, (int) ($cfg['automation_lock_wait_seconds'] ?? 20)),
                ]);

                try {
                    $process->run();
                } catch (ProcessTimedOutException $e) {
                    Log::channel('yelp')->error('Yelp: upload script timed out', [
                        'image_id' => $image->id,
                        'message' => $e->getMessage(),
                    ]);
                    return null;
                }

                $stdout = trim($process->getOutput());
                $stderr = trim($process->getErrorOutput());

                if (! $process->isSuccessful()) {
                    Log::channel('yelp')->error('Yelp: upload script exited with error', [
                        'image_id' => $image->id,
                        'exit_code' => $process->getExitCode(),
                        'stderr' => $stderr,
                        'stdout' => $stdout,
                    ]);
                    return null;
                }

                // Script prints a single JSON line on stdout: {"ok":true,"photo_id":"..."}
                $jsonLine = $this->lastJsonLine($stdout);
                $payload = $jsonLine ? json_decode($jsonLine, true) : null;

                if (! is_array($payload) || empty($payload['ok'])) {
                    Log::channel('yelp')->error('Yelp: upload script returned no/invalid payload', [
                        'image_id' => $image->id,
                        'stdout' => $stdout,
                        'stderr' => $stderr,
                    ]);
                    return null;
                }

                return $payload['photo_id'] ?? ('uploaded-' . now()->timestamp);
            },
            context: ['image_id' => $image->id]
        );
    }

    /**
     * Upload a single ProjectImage to the account-wide Yelp Business Photos
     * gallery (biz.yelp.com/biz_photos). Unlike uploadProjectImage() this
     * does not require a per-project portfolio URL.
     *
     * Returns: ['photo_id' => string, 'photos_url' => string] on success, null on failure.
     */
    public function uploadProjectImageToBusinessPhotos(ProjectImage $image, ?callable $onProgress = null): ?array
    {
        $absolutePath = $this->resolveAbsolutePath($image);
        if (! $absolutePath) {
            Log::channel('yelp')->warning('Yelp biz: source image not found on disk', [
                'image_id' => $image->id,
                'path' => $image->path,
            ]);
            return null;
        }

        $cfg = config('services.yelp.business');
        $script = base_path('scripts/yelp-upload-business-photo.mjs');
        $caption = $this->buildCaption($image);

        $args = [
            $cfg['node_binary'] ?? 'node',
            $script,
            '--photo=' . $absolutePath,
            '--caption=' . $caption,
            '--user-data-dir=' . ($cfg['user_data_dir'] ?? storage_path('app/yelp-puppeteer')),
            '--email=' . $this->getEmail(),
            '--password=' . $this->getPassword(),
            '--timeout-ms=' . (int) ($cfg['timeout_ms'] ?? 180000),
        ];

        if (! empty($cfg['biz_photos_url'])) {
            $args[] = '--photos-url=' . $cfg['biz_photos_url'];
        }
        if (! empty($cfg['headed'])) {
            $args[] = '--headed';
        }
        $cookiesFile = storage_path('app/yelp-cookies.json');
        $hasCookies = is_file($cookiesFile) && filesize($cookiesFile) > 0;
        if ($hasCookies) {
            $args[] = '--cookies-file=' . $cookiesFile;
        }
        if (! $hasCookies && ! empty($cfg['proxy'])) {
            // Force unique exit IP on every upload so a burned proxy IP
            // from a prior run doesn't poison this one. Supports both
            // IPRoyal `_session-XYZ` rotation and Bright Data
            // `-session-XYZ` username injection.
            $proxyUrl = $this->forceUniqueProxySession((string) $cfg['proxy'], 'YelpUp');
            $args[] = '--proxy=' . $proxyUrl;
        }
        if ($key = config('services.twocaptcha.api_key')) {
            $args[] = '--twocaptcha-key=' . $key;
        }
        if ($key = config('services.anticaptcha.api_key')) {
            $args[] = '--anticaptcha-key=' . $key;
        }

        return $this->withAutomationLock(
            operation: 'business_photos_upload',
            callback: function () use ($args, $cfg, $image, $onProgress, $caption): ?array {
                // Crash-safe claim: stamp an in-flight marker BEFORE spawning
                // Chromium. If the parent PHP process is SIGKILL'd (OOM,
                // deploy, manual kill) between Yelp accepting the photo and
                // us reading the success JSON, the next retry sees this
                // marker and refuses to re-upload — which would create a
                // duplicate photo on Yelp. We use a Cache key (not a
                // platform_uploads row) so the upload progress bar's
                // "done = has platform_uploads row" counter is unaffected.
                $claimKey = self::inFlightCacheKey($image->id);
                Cache::put($claimKey, json_encode([
                    'image_id' => $image->id,
                    'caption' => $caption,
                    'started_at' => now()->toIso8601String(),
                ]), now()->addHour());

                $timeoutSec = ((int) ($cfg['timeout_ms'] ?? 180000)) / 1000 + 30;
                $process = new Process($this->wrapWithFlock($args), base_path());
                $process->setTimeout($timeoutSec);
                $process->setEnv($this->browserProcessEnv() + [
                    'YELP_RUN_TIMEOUT' => (string) (int) ($timeoutSec - 10),
                    'YELP_RUN_LOCK_WAIT' => (string) max(0, (int) ($cfg['automation_lock_wait_seconds'] ?? 20)),
                ]);

                // Always tee the script's stderr to a dedicated log file so
                // diagnostic lines like "[yelp] captured real photo_id=..."
                // are visible even when running via Horizon (where the
                // $onProgress callback is null). Trimmed to last 5MB on each
                // run to avoid unbounded growth.
                $teeLog = storage_path('logs/yelp-upload.log');
                @mkdir(dirname($teeLog), 0775, true);
                $teeFh = @fopen($teeLog, 'ab');
                if ($teeFh) {
                    @fwrite($teeFh, sprintf(
                        "\n===== %s image_id=%d =====\n",
                        now()->toIso8601String(),
                        $image->id,
                    ));
                }

                try {
                    $process->run(function (string $type, string $buffer) use ($onProgress, $teeFh): void {
                        if ($teeFh) {
                            @fwrite($teeFh, $buffer);
                        }
                        if (! $onProgress) {
                            return;
                        }
                        foreach (preg_split('/\r?\n/', $buffer) ?: [] as $line) {
                            $line = trim($line);
                            if ($line !== '') {
                                $onProgress($type, $line);
                            }
                        }
                    });
                } catch (ProcessTimedOutException $e) {
                    // Symfony tripped its own wall-clock timeout (i.e. the
                    // bash wrapper failed to return in time). Belt-and-
                    // suspenders: SIGKILL the Symfony child group AND any
                    // chromium still holding our userDataDir, so the
                    // automation lock is released and the next job can run.
                    Log::channel('yelp')->error('Yelp biz: upload script timed out', [
                        'image_id' => $image->id,
                        'message' => $e->getMessage(),
                    ]);
                    try {
                        $pid = $process->getPid();
                        if ($pid) {
                            @posix_kill(-$pid, SIGKILL);
                            @posix_kill($pid, SIGKILL);
                        }
                    } catch (\Throwable $ignored) {}
                    $userDataDir = (string) (config('services.yelp.business.user_data_dir') ?: '');
                    if ($userDataDir !== '') {
                        @exec('pkill -KILL -f ' . escapeshellarg('user-data-dir=' . $userDataDir));
                    }
                    if ($teeFh) {
                        @fclose($teeFh);
                    }
                    return null;
                } catch (ProcessSignaledException $e) {
                    // Subprocess was killed by a signal (typically SIGKILL
                    // from the bash wrapper / cleanup_pgid after chromium
                    // teardown hung). Symfony's run() THROWS in this case
                    // instead of returning, so we'd otherwise never reach
                    // the success-rescue parser below. Check stdout right
                    // here: if the upload actually completed and printed
                    // the ok:true JSON before the kill, persist it. The
                    // photo is on Yelp - we must not lose the photo_id.
                    if ($teeFh) {
                        @fclose($teeFh);
                    }
                    $stdoutSignaled = trim($process->getOutput());
                    $stderrSignaled = trim($process->getErrorOutput());
                    $jsonLineSignaled = $this->lastJsonLine($stdoutSignaled);
                    $payloadSignaled = $jsonLineSignaled ? json_decode($jsonLineSignaled, true) : null;
                    if (is_array($payloadSignaled) && ! empty($payloadSignaled['ok']) && ! empty($payloadSignaled['photo_id'])) {
                        Log::channel('yelp')->warning('Yelp biz: subprocess signaled after success JSON; persisting payload anyway', [
                            'image_id' => $image->id,
                            'signal' => $e->getProcess()->getTermSignal(),
                            'photo_id' => $payloadSignaled['photo_id'],
                        ]);
                        $this->markSessionFresh();
                        $realPhotoId = is_string($payloadSignaled['photo_id']) && $payloadSignaled['photo_id'] !== ''
                            ? $payloadSignaled['photo_id']
                            : null;
                        \App\Models\ImagePlatformUpload::record($image->id, \App\Models\ImagePlatformUpload::PLATFORM_YELP_BIZ, [
                            'remote_id' => $realPhotoId,
                            'caption' => $caption,
                        ]);
                        Cache::forget(self::inFlightCacheKey($image->id));
                        return [
                            'photo_id' => $image->fresh()->yelp_biz_photo_id,
                            'photos_url' => $payloadSignaled['photos_url'] ?? null,
                            'caption' => $caption,
                            'verified' => (bool) ($payloadSignaled['photo_id_verified'] ?? false),
                        ];
                    }
                    Log::channel('yelp')->error('Yelp biz: subprocess killed by signal with no success payload', [
                        'image_id' => $image->id,
                        'signal' => $e->getProcess()->getTermSignal(),
                        'stdout_tail' => mb_substr($stdoutSignaled, -2000),
                        'stderr_tail' => mb_substr($stderrSignaled, -2000),
                    ]);
                    // Clear in-flight marker - the upload either didn't
                    // start or didn't reach success; safe to let the job
                    // retry without manual intervention.
                    Cache::forget(self::inFlightCacheKey($image->id));
                    return null;
                }

                if ($teeFh) {
                    @fclose($teeFh);
                }
                // Cap the tee log at ~5MB by truncating to the last 5MB
                // whenever it grows beyond ~6MB. Cheap, no log-rotate dep.
                if (is_file($teeLog) && filesize($teeLog) > 6 * 1024 * 1024) {
                    $tail = @file_get_contents($teeLog, false, null, -5 * 1024 * 1024);
                    if ($tail !== false) {
                        @file_put_contents($teeLog, $tail);
                    }
                }

                $stdout = trim($process->getOutput());
                $stderr = trim($process->getErrorOutput());

                // Parse the success payload BEFORE checking $process->isSuccessful().
                // Chromium teardown after a successful upload occasionally hangs and
                // the bash wrapper / OS ends up SIGKILL'ing the subprocess group —
                // Symfony reports "signaled with signal 9" but the upload IS done
                // and stdout already contains {"ok":true,"photo_id":"..."}. We must
                // not throw that away: the photo is on Yelp and we need the DB row.
                $earlyJsonLine = $this->lastJsonLine($stdout);
                $earlyPayload = $earlyJsonLine ? json_decode($earlyJsonLine, true) : null;
                $earlyOk = is_array($earlyPayload) && ! empty($earlyPayload['ok']);

                if (! $process->isSuccessful() && ! $earlyOk) {
                    // Exit code 3 = structured session_expired signal from the
                    // upload script. We still parse stdout JSON to confirm and
                    // capture any human-readable note for the admin UI.
                    $exit = (int) $process->getExitCode();
                    $jsonLine = $earlyJsonLine;
                    $payload = $earlyPayload;

                    // Exit code 75 (EX_TEMPFAIL) = recoverable throttle signal
                    // (e.g. /biz_photos/<id> returned "Oops! Something went
                    // wrong"). Throw the throttle exception so the calling
                    // job releases itself back to the queue with the
                    // requested delay instead of failing. This frees the
                    // worker for other jobs during the cool-down.
                    $isThrottle = $exit === 75
                        || (is_array($payload) && ! empty($payload['throttled']));
                    if ($isThrottle) {
                        $retryAfter = is_array($payload)
                            ? (int) ($payload['retry_after_seconds'] ?? 5)
                            : 5;
                        $reason = is_array($payload)
                            ? (string) ($payload['reason'] ?? 'script_throttle')
                            : 'script_throttle';
                        // Operator preference: do NOT block the host on Yelp
                        // Oops; just release this single job with a short
                        // back-off and let the next image try. Cap retries
                        // to avoid forever-looping on a persistent Oops.
                        $retryAfter = min(30, max(5, $retryAfter));
                        // Clean throttle exit — script never attempted the
                        // upload, so the in-flight "killed mid-upload" guard
                        // must not trip on the retry.
                        Cache::forget(self::inFlightCacheKey($image->id));
                        Log::channel('yelp')->info('Yelp biz: upload script signalled throttle, releasing job', [
                            'image_id' => $image->id,
                            'reason' => $reason,
                            'retry_after_seconds' => $retryAfter,
                        ]);
                        throw new YelpUploadThrottledException(
                            "Yelp upload throttled ({$reason}); retry in {$retryAfter}s",
                            $retryAfter,
                            $reason,
                        );
                    }

                    $isSessionDead = $exit === 3
                        || (is_array($payload) && ($payload['code'] ?? null) === 'session_expired');

                    if ($isSessionDead) {
                        $note = is_array($payload) ? (string) ($payload['error'] ?? '') : '';
                        $this->markSessionDead($note);
                        // Surface 4KB of stderr tail (not 2KB) because the new
                        // cookie summary line at session-fail can easily be
                        // 1.5KB on its own, and we'd otherwise lose the
                        // preceding [yelp] navigation breadcrumbs.
                        Log::channel('yelp')->warning('Yelp biz: session expired - admin must re-login via /admin/platforms', [
                            'image_id' => $image->id,
                            'note' => $note,
                            'stderr_tail' => mb_substr($stderr, -4000),
                        ]);
                        throw new YelpSessionExpiredException(
                            $note !== '' ? $note : 'Yelp session is not authenticated.'
                        );
                    }

                    Log::channel('yelp')->error('Yelp biz: upload script exited with error', [
                        'image_id' => $image->id,
                        'exit_code' => $exit,
                        'stderr' => $stderr,
                        'stdout' => $stdout,
                    ]);
                    return null;
                }

                $jsonLine = $earlyJsonLine;
                $payload = $earlyPayload;

                if (! is_array($payload) || empty($payload['ok'])) {
                    Log::channel('yelp')->error('Yelp biz: upload script returned no/invalid payload', [
                        'image_id' => $image->id,
                        'exit_code' => (int) $process->getExitCode(),
                        'stdout' => $stdout,
                        'stderr' => $stderr,
                    ]);
                    return null;
                }

                // If the subprocess died via signal AFTER printing ok:true,
                // log it loudly but proceed with the recorded success — the
                // photo is on Yelp and we have the verified photo_id.
                if (! $process->isSuccessful()) {
                    Log::channel('yelp')->warning('Yelp biz: subprocess signaled after success JSON; persisting payload anyway', [
                        'image_id' => $image->id,
                        'exit_code' => (int) $process->getExitCode(),
                        'photo_id' => $payload['photo_id'] ?? null,
                    ]);
                }

                // A successful upload proves the session cookies still work.
                $this->markSessionFresh();
                // Reset the consecutive-Oops streak: Yelp is healthy again.
                Cache::forget(self::OOPS_STREAK_KEY);

                // The script returns a real Yelp photo_id only when it
                // successfully captured one from the upload XHR response.
                // If null, the upload almost certainly went through (the
                // dialog closed) but we couldn't verify it, so we store
                // NULL rather than a synthetic stamp the gallery can never
                // resolve. A later verification job can backfill it.
                $realPhotoId = is_string($payload['photo_id'] ?? null) && $payload['photo_id'] !== ''
                    ? $payload['photo_id']
                    : null;
                $verified = (bool) ($payload['photo_id_verified'] ?? false);

                if (! $verified) {
                    Log::channel('yelp')->warning('Yelp biz: upload committed but photo_id not captured - storing NULL for later verification', [
                        'image_id' => $image->id,
                        'photos_url' => $payload['photos_url'] ?? null,
                    ]);
                }

                \App\Models\ImagePlatformUpload::record($image->id, \App\Models\ImagePlatformUpload::PLATFORM_YELP_BIZ, [
                    'remote_id' => $realPhotoId,
                    'caption' => $caption,
                ]);
                // Successful commit — drop the in-flight crash-safe marker.
                Cache::forget(self::inFlightCacheKey($image->id));

                return [
                    'photo_id' => $image->fresh()->yelp_biz_photo_id,
                    'photos_url' => $payload['photos_url'] ?? null,
                    'caption' => $caption,
                    'verified' => $verified,
                ];
            },
            context: ['image_id' => $image->id]
        );
    }

    /**
     * Ensure all Yelp browser automations are globally serialized.
     * This prevents overlapping Chromium runs when commands/jobs overlap.
     *
     * @template T
     * @param  callable():T  $callback
     * @param  array<string,mixed>  $context
     * @return T|null
     */
    protected function withAutomationLock(string $operation, callable $callback, array $context = []): mixed
    {
        $cfg = config('services.yelp.business');
        $lockTtl = max(60, (int) ($cfg['automation_lock_ttl_seconds'] ?? 900));
        $lockWait = max(0, (int) ($cfg['automation_lock_wait_seconds'] ?? 5));
        $minInterval = max(0, (int) ($cfg['min_interval_seconds'] ?? 600));

        // 0. Host-wide script-throttle cooldown. When the Node uploader
        //    last signalled e.g. photos_page_oops, Yelp's /biz_photos page
        //    is unusable for ~10min. Bailing here (before lock + Chromium
        //    launch) saves ~5s and ~800MB RSS per pending job.
        $cooldownUntil = (int) Cache::get(self::OOPS_COOLDOWN_KEY, 0);
        if ($cooldownUntil > time()) {
            $retryAfter = $cooldownUntil - time();
            Log::channel('yelp')->debug('Yelp: in host-wide cooldown, skipping', [
                'operation' => $operation,
                'retry_after_seconds' => $retryAfter,
            ] + $context);
            throw new YelpUploadThrottledException(
                "Yelp automation cooling down; retry in {$retryAfter}s",
                $retryAfter,
            );
        }

        // 1. Hard throttle: enforce a minimum gap between successful runs.
        //    This is the primary safeguard against server overload —
        //    Chromium is heavy, so we cap real launches at one per
        //    min_interval_seconds across the entire host.
        //    media-sync runs with maxProcesses=1, so we sleep in-process
        //    rather than throwing; releasing back to the queue caused a
        //    cycle where each job re-checked the throttle against a fresh
        //    LAST_RUN_KEY after every successful upload, multiplying the
        //    inter-upload delay by 3-4× per pending job.
        if ($minInterval > 0) {
            $lastRunAt = (int) Cache::get(self::LAST_RUN_KEY, 0);
            $elapsed = time() - $lastRunAt;
            if ($lastRunAt > 0 && $elapsed < $minInterval) {
                $waitSeconds = $minInterval - $elapsed;
                Log::channel('yelp')->debug('Yelp: sleeping for min_interval_seconds', [
                    'operation' => $operation,
                    'min_interval_seconds' => $minInterval,
                    'wait_seconds' => $waitSeconds,
                ] + $context);
                sleep($waitSeconds);
            }
        }

        $lock = Cache::lock(self::AUTOMATION_LOCK_KEY, $lockTtl);

        try {
            if ($lockWait > 0) {
                $startedAt = microtime(true);
                $result = $lock->block($lockWait, function () use ($callback, $operation, $context) {
                    Log::channel('yelp')->info('Yelp: automation lock acquired', ['operation' => $operation] + $context);
                    Cache::put(self::CURRENT_OP_KEY, json_encode([
                        'operation' => $operation,
                        'image_id' => $context['image_id'] ?? null,
                        'started_at' => time(),
                    ]), now()->addMinutes(15));
                    try {
                        return $callback();
                    } finally {
                        Cache::forget(self::CURRENT_OP_KEY);
                    }
                });
                Cache::put(self::LAST_RUN_KEY, time(), now()->addDay());
                Log::channel('yelp')->info('Yelp: automation lock released (ok)', [
                    'operation' => $operation,
                    'duration_seconds' => round(microtime(true) - $startedAt, 1),
                ] + $context);
                return $result;
            }

            if (! $lock->get()) {
                Log::channel('yelp')->warning('Yelp: automation lock busy', ['operation' => $operation] + $context);
                throw new YelpUploadThrottledException(
                    'Yelp automation lock busy',
                    max(60, $minInterval ?: 60),
                );
            }

            Log::channel('yelp')->info('Yelp: automation lock acquired', ['operation' => $operation] + $context);
            Cache::put(self::CURRENT_OP_KEY, json_encode([
                'operation' => $operation,
                'image_id' => $context['image_id'] ?? null,
                'started_at' => time(),
            ]), now()->addMinutes(15));
            $startedAt = microtime(true);
            try {
                $result = $callback();
                Cache::put(self::LAST_RUN_KEY, time(), now()->addDay());
                return $result;
            } finally {
                Cache::forget(self::CURRENT_OP_KEY);
                $lock->release();
                Log::channel('yelp')->info('Yelp: automation lock released', [
                    'operation' => $operation,
                    'duration_seconds' => round(microtime(true) - $startedAt, 1),
                ] + $context);
            }
        } catch (LockTimeoutException) {
            // Smarter retry-after: a typical upload takes ~120s. Backing off
            // for ~upload_duration prevents N pending jobs from all retrying
            // every 60s and flooding the log. Add small per-job jitter
            // (0-15s) to prevent thundering-herd collisions when the holder
            // finishes and multiple jobs wake up simultaneously.
            $retryAfter = 90 + random_int(0, 30);
            // Debug-level: this is the expected hot-path for a queue of
            // pending jobs while one is uploading. WARNING would log N lines
            // per upload cycle (one per pending job per retry).
            Log::channel('yelp')->debug('Yelp: automation lock wait timed out', [
                'operation' => $operation,
                'wait_seconds' => $lockWait,
                'retry_after_seconds' => $retryAfter,
            ] + $context);
            throw new YelpUploadThrottledException(
                'Yelp automation lock wait timed out',
                $retryAfter,
            );
        }
    }

    protected function resolveAbsolutePath(ProjectImage $image): ?string
    {
        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($image->path)) {
                return null;
            }
            // local-style disks expose path()
            if (method_exists($disk, 'path')) {
                return $disk->path($image->path);
            }
            // Fallback: stream to tmp file
            $tmp = tempnam(sys_get_temp_dir(), 'yelp-img-');
            file_put_contents($tmp, $disk->get($image->path));
            return $tmp;
        } catch (\Throwable $e) {
            Log::channel('yelp')->error('Yelp: failed to resolve image path', [
                'image_id' => $image->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function buildCaption(ProjectImage $image): string
    {
        $source = trim((string) ($image->caption ?? ''));
        if ($source === '') {
            $source = trim((string) ($image->seo_alt_text ?? $image->alt_text ?? ''));
        }
        if ($source === '') {
            $source = trim((string) ($image->project?->title ?? 'Project photo'));
        }

        $limit = 140;
        // Reserve space for the idempotency marker (`· #g{id}`) so the final
        // caption + marker stays within Yelp's 140-char cap.
        $marker = $this->idempotencyMarker($image);
        $usable = max(40, $limit - mb_strlen($marker));

        // Try the Gemini SEO rewrite first.
        $seo = app(AiContentService::class)->shortenCaptionForSeo($image, $usable);
        if (is_string($seo) && $seo !== '' && mb_strlen($seo) <= $usable) {
            return $this->withIdempotencyMarker($seo, $image, $limit);
        }

        // Gemini failed (or was rate-limited / unavailable). Build a
        // deterministic, guaranteed-valid caption from project data so the
        // upload never blocks on caption generation.
        $base = $this->buildFallbackCaption($image, $source, $usable);
        return $this->withIdempotencyMarker($base, $image, $limit);
    }

    /**
     * Stable per-image marker appended to every caption. Disabled: the
     * signal-9 rescue path in uploadProjectImageToBusinessPhotos() now
     * persists the photo_id straight from stdout, so we no longer need a
     * visible caption anchor to recover from crashes. Returning '' makes
     * withIdempotencyMarker() a no-op.
     */
    protected function idempotencyMarker(ProjectImage $image): string
    {
        return '';
    }

    protected function withIdempotencyMarker(string $caption, ProjectImage $image, int $limit): string
    {
        $marker = $this->idempotencyMarker($image);
        if (str_contains($caption, trim($marker))) {
            return $caption;
        }
        $caption = rtrim($caption);
        if (mb_strlen($caption . $marker) > $limit) {
            $caption = rtrim(mb_substr($caption, 0, $limit - mb_strlen($marker)), " \t.!?;:,-");
        }
        return $caption . $marker;
    }

    /**
     * Deterministic caption builder. ALWAYS returns a non-empty string ≤ $limit
     * chars ending with ".". Used when Gemini is unavailable or fails the
     * quality gate. Strips filler/cosmetic words so the output is still clean
     * enough to ship.
     */
    protected function buildFallbackCaption(ProjectImage $image, string $source, int $limit): string
    {
        $project = $image->project;
        $city = trim((string) ($project?->location ?? ''));
        $city = preg_replace('/\s*,\s*[A-Z]{2}\b.*$/', '', $city) ?? $city;
        $type = $project?->project_type
            ? strtolower(str_replace(['-', '_'], ' ', (string) $project->project_type))
            : 'home remodel';

        // Strip filler adjectives + cosmetic terms so the fallback doesn't
        // ship "stunning white quartz". This is a coarse pass — good enough
        // for a last-resort caption.
        $cleaned = $this->stripCosmeticAndFiller($source);
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned, " \t,;:-");

        if ($cleaned !== '') {
            // Take just the first sentence (or full string if no terminator).
            if (preg_match('/^(.+?[.!?])(\s|$)/u', $cleaned, $m)) {
                $candidate = trim($m[1]);
            } else {
                $candidate = $cleaned;
            }
            // Ensure terminal period and strip trailing junk.
            $candidate = rtrim($candidate, " \t.!?;:,-") . '.';

            // If too long, cut at the last comma/space within the limit so we
            // never end with a dangling preposition or article.
            if (mb_strlen($candidate) > $limit) {
                $cut = mb_substr($candidate, 0, $limit - 1);
                $boundary = max(
                    (int) mb_strrpos($cut, ', '),
                    (int) mb_strrpos($cut, '; '),
                    (int) mb_strrpos($cut, ' and '),
                    (int) mb_strrpos($cut, ' with '),
                    (int) mb_strrpos($cut, ' featuring ')
                );
                if ($boundary > $limit - 60) {
                    $candidate = rtrim(mb_substr($cut, 0, $boundary), " ,;:-") . '.';
                } else {
                    // No clean clause boundary — fall through to generic.
                    $candidate = '';
                }
            }

            if ($candidate !== '' && $candidate !== '.' && mb_strlen($candidate) <= $limit) {
                return $candidate;
            }
        }

        // Generic last-resort caption from project metadata. Always valid.
        $cityPart = $city !== '' ? " in {$city}" : '';
        $generic = "GS Construction {$type} project{$cityPart}.";
        if (mb_strlen($generic) > $limit) {
            $generic = mb_substr($generic, 0, $limit - 1) . '.';
        }
        return $generic;
    }

    /**
     * Coarse pass to remove cosmetic/material/filler words from a caption.
     * Used only by the fallback path — Gemini handles this properly when up.
     */
    protected function stripCosmeticAndFiller(string $text): string
    {
        // Words/phrases to drop entirely. Order matters: longer first so we
        // remove "white quartz countertop" before "white" / "quartz".
        $patterns = [
            // multi-word cosmetic phrases
            '/\b(?:white|black|gray|grey|blue|green|dark|warm|rich)\s+(?:quartz|marble|granite|stone|tile|hardwood|wood|shaker|subway|herringbone|matte|stainless|brass|gold|bronze|silver|chrome)\s+\w+/i',
            '/\b(?:quartz|marble|granite|hardwood|stainless|herringbone|subway|shaker)\s+\w+/i',
            // filler adjectives
            '/\b(?:stunning|beautiful|beautifully|gorgeous|modern|sleek|elegant|elegantly|luxurious|spa-like|sophisticated|stylish|stylishly|classic|spacious|bright|functional|breathtaking|amazing|perfect|dream|charming)\b\s*/i',
            // material/finish single words (when not caught above)
            '/\b(?:quartz|marble|granite|hardwood|tile|tiled|subway|shaker|herringbone|matte|stainless|chrome|brass)\b\s*/i',
            // people refs
            '/\b(?:homeowner|homeowners|owner|owners|client|clients|family|families)\b\s*/i',
            // ", IL" / state codes
            '/,\s*[A-Z]{2}\b/',
        ];
        foreach ($patterns as $p) {
            $text = preg_replace($p, ' ', $text) ?? $text;
        }
        // Collapse leftover punctuation/whitespace artifacts.
        $text = preg_replace('/\s+,/u', ',', $text) ?? $text;
        $text = preg_replace('/,\s*,/u', ',', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    protected function lastJsonLine(string $stdout): ?string
    {
        foreach (array_reverse(preg_split('/\r?\n/', $stdout) ?: []) as $line) {
            $line = trim($line);
            if ($line !== '' && str_starts_with($line, '{') && str_ends_with($line, '}')) {
                return $line;
            }
        }
        return null;
    }

    /**
     * Force a fresh upstream session on a proxy URL. Handles IPRoyal
     * (`_session-X_lifetime-...`) by rewriting and Bright Data
     * (`brd-customer-X-zone-Y`) by injecting `-session-X` in the username.
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
        // No session marker — leave URL untouched (see YelpRemoteLoginService
        // for rationale). Per-request rotation by the gateway is sufficient.
        return $proxyUrl;
    }
}
