<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\ProjectImage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use App\Exceptions\YelpUploadThrottledException;
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

        Log::info('Yelp: launching headed login browser', [
            'cmd' => $cmd,
            'display' => $display,
            'xauthority' => $xauth,
        ]);

        $pid = trim((string) shell_exec($cmd));
        if ($pid === '' || ! ctype_digit($pid)) {
            Log::error('Yelp: login browser launch returned no PID', ['pid' => $pid]);
            return false;
        }

        Log::info('Yelp: headed login browser launched', ['pid' => $pid, 'log' => $logFile]);
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
            Log::warning('Yelp: checkSession timed out');
            return null;
        } catch (ProcessSignaledException $e) {
            Log::warning('Yelp: checkSession subprocess killed', [
                'signal' => $e->getSignal(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 500),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::warning('Yelp: checkSession failed', ['error' => $e->getMessage()]);
            return null;
        }

        $payload = json_decode($this->lastJsonLine(trim($process->getOutput())) ?: '', true);
        if (! is_array($payload) || empty($payload['ok'])) {
            return null;
        }
        $authed = (bool) ($payload['authenticated'] ?? false);
        Cache::put('yelp.last_auth', $authed, now()->addHours(6));
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
            Log::info('Yelp: skipping upload - project has no yelp_portfolio_url', [
                'image_id' => $image->id,
                'project_id' => $project?->id,
            ]);
            return null;
        }

        $absolutePath = $this->resolveAbsolutePath($image);
        if (! $absolutePath) {
            Log::warning('Yelp: source image not found on disk', [
                'image_id' => $image->id,
                'disk' => $image->disk,
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
                    Log::error('Yelp: upload script timed out', [
                        'image_id' => $image->id,
                        'message' => $e->getMessage(),
                    ]);
                    return null;
                }

                $stdout = trim($process->getOutput());
                $stderr = trim($process->getErrorOutput());

                if (! $process->isSuccessful()) {
                    Log::error('Yelp: upload script exited with error', [
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
                    Log::error('Yelp: upload script returned no/invalid payload', [
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
            Log::warning('Yelp biz: source image not found on disk', [
                'image_id' => $image->id,
                'disk' => $image->disk,
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
        if (! empty($cfg['proxy'])) {
            $args[] = '--proxy=' . $cfg['proxy'];
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
                $timeoutSec = ((int) ($cfg['timeout_ms'] ?? 180000)) / 1000 + 30;
                $process = new Process($this->wrapWithFlock($args), base_path());
                $process->setTimeout($timeoutSec);
                $process->setEnv($this->browserProcessEnv() + [
                    'YELP_RUN_TIMEOUT' => (string) (int) ($timeoutSec - 10),
                    'YELP_RUN_LOCK_WAIT' => (string) max(0, (int) ($cfg['automation_lock_wait_seconds'] ?? 20)),
                ]);

                try {
                    $process->run(function (string $type, string $buffer) use ($onProgress): void {
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
                    Log::error('Yelp biz: upload script timed out', [
                        'image_id' => $image->id,
                        'message' => $e->getMessage(),
                    ]);
                    return null;
                }

                $stdout = trim($process->getOutput());
                $stderr = trim($process->getErrorOutput());

                if (! $process->isSuccessful()) {
                    Log::error('Yelp biz: upload script exited with error', [
                        'image_id' => $image->id,
                        'exit_code' => $process->getExitCode(),
                        'stderr' => $stderr,
                        'stdout' => $stdout,
                    ]);
                    return null;
                }

                $jsonLine = $this->lastJsonLine($stdout);
                $payload = $jsonLine ? json_decode($jsonLine, true) : null;

                if (! is_array($payload) || empty($payload['ok'])) {
                    Log::error('Yelp biz: upload script returned no/invalid payload', [
                        'image_id' => $image->id,
                        'stdout' => $stdout,
                        'stderr' => $stderr,
                    ]);
                    return null;
                }

                // A successful upload proves the session cookies still work.
                $this->markSessionFresh();

                $image->update([
                    'yelp_biz_photo_id' => $payload['photo_id'] ?? ('uploaded-' . now()->timestamp),
                    'yelp_biz_uploaded_at' => now(),
                    'yelp_biz_photos_url' => $payload['photos_url'] ?? $image->yelp_biz_photos_url,
                    'yelp_biz_caption' => $caption,
                ]);

                return [
                    'photo_id' => $image->yelp_biz_photo_id,
                    'photos_url' => $image->yelp_biz_photos_url,
                    'caption' => $caption,
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

        // 1. Hard throttle: enforce a minimum gap between successful runs.
        //    This is the primary safeguard against server overload —
        //    Chromium is heavy, so we cap real launches at one per
        //    min_interval_seconds across the entire host.
        if ($minInterval > 0) {
            $lastRunAt = (int) Cache::get(self::LAST_RUN_KEY, 0);
            $elapsed = time() - $lastRunAt;
            if ($lastRunAt > 0 && $elapsed < $minInterval) {
                $retryAfter = $minInterval - $elapsed;
                Log::info('Yelp: throttled by min_interval_seconds', [
                    'operation' => $operation,
                    'min_interval_seconds' => $minInterval,
                    'retry_after_seconds' => $retryAfter,
                ] + $context);
                throw new YelpUploadThrottledException(
                    "Yelp automation throttled; retry in {$retryAfter}s",
                    $retryAfter,
                );
            }
        }

        $lock = Cache::lock(self::AUTOMATION_LOCK_KEY, $lockTtl);

        try {
            if ($lockWait > 0) {
                $result = $lock->block($lockWait, function () use ($callback, $operation, $context) {
                    Log::info('Yelp: automation lock acquired', ['operation' => $operation] + $context);
                    return $callback();
                });
                Cache::put(self::LAST_RUN_KEY, time(), now()->addDay());
                return $result;
            }

            if (! $lock->get()) {
                Log::warning('Yelp: automation lock busy', ['operation' => $operation] + $context);
                throw new YelpUploadThrottledException(
                    'Yelp automation lock busy',
                    max(60, $minInterval ?: 60),
                );
            }

            Log::info('Yelp: automation lock acquired', ['operation' => $operation] + $context);
            try {
                $result = $callback();
                Cache::put(self::LAST_RUN_KEY, time(), now()->addDay());
                return $result;
            } finally {
                $lock->release();
            }
        } catch (LockTimeoutException) {
            Log::warning('Yelp: automation lock wait timed out', [
                'operation' => $operation,
                'wait_seconds' => $lockWait,
            ] + $context);
            throw new YelpUploadThrottledException(
                'Yelp automation lock wait timed out',
                max(60, $minInterval ?: 60),
            );
        }
    }

    protected function resolveAbsolutePath(ProjectImage $image): ?string
    {
        try {
            $disk = Storage::disk($image->disk ?: 'public');
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
            Log::error('Yelp: failed to resolve image path', [
                'image_id' => $image->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function buildCaption(ProjectImage $image): string
    {
        $caption = trim((string) ($image->caption ?? ''));
        if ($caption === '') {
            $caption = trim((string) ($image->seo_alt_text ?? $image->alt_text ?? ''));
        }
        if ($caption === '') {
            $caption = trim((string) ($image->project?->title ?? 'Project photo'));
        }

        $limit = 140;

        // Keyword-rich Gemini rewrite. We do NOT fall back to a plain
        // mb_substr truncation on failure — a mid-word cut on Yelp looks
        // worse than skipping the upload. Let the exception bubble; the
        // job will fail loudly with the bad output in the logs.
        $seo = app(AiContentService::class)->shortenCaptionForSeo($image, $limit);
        if (is_string($seo) && $seo !== '' && mb_strlen($seo) <= $limit) {
            return $seo;
        }

        throw new \RuntimeException(sprintf(
            'Yelp caption rewrite returned no usable result for image #%d.',
            $image->id
        ));
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
}
