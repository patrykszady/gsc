<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\ProjectImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
     * Quick headless check: are biz.yelp.com cookies still authenticated?
     * Returns null if it could not determine (script error / timeout).
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
            return null;
        }

        $payload = json_decode($this->lastJsonLine(trim($process->getOutput())) ?: '', true);
        if (! is_array($payload) || empty($payload['ok'])) {
            return null;
        }
        return (bool) ($payload['authenticated'] ?? false);
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

        $process = new Process($args, base_path());
        $process->setTimeout(((int) ($cfg['timeout_ms'] ?? 180000)) / 1000 + 30);

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
        return mb_substr($caption, 0, 240);
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
