<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Throwable;

/**
 * Runs one seo-reports.php command for the admin's "Run" button and logs the
 * whole thing to the 'seo-reports' channel, start to finish. Before this,
 * regenerate() ran Artisan::call() inline with nothing logged anywhere and
 * a bare "Failed to regenerate: …" string as the only trace of what
 * happened — a missing command and a genuine failure looked identical to
 * the operator and were invisible to us. Every run now leaves a start line,
 * a finish line (or an error line) in storage/logs/seo-reports-*.log,
 * readable through the central log viewer, and an honest status back to
 * the caller.
 */
class SeoReportRun
{
    /**
     * @param  array{label: string, command: string, description?: string}  $meta
     * @return array{status: string, ok: bool, message: string, started_at: string, finished_at: string, duration_ms: int, exit_code: int|null, output_tail: string, log_channel: string}
     */
    public static function run(string $key, array $meta, Request $request, int $trendDays): array
    {
        $command = $meta['command'];
        $label = $meta['label'];

        $context = [
            'key' => $key,
            'label' => $label,
            'command' => $command,
            'trend_days' => $trendDays,
            'site' => Site::current()?->slug,
            'requested_by' => $request->header('X-Admin-User'),
            'screen' => $request->header('X-Admin-Screen'),
            'ip' => $request->ip(),
        ];

        Log::channel('seo-reports')->info('report run started', $context);

        $startedAt = Carbon::now();
        $start = microtime(true);
        $status = 'ok';
        $exitCode = null;
        $output = '';
        $errorMessage = null;

        try {
            $exitCode = Artisan::call($command);
            $output = Artisan::output();
            // A non-zero exit with the report freshly written is a health
            // signal, not a broken run: seo:health exits 1 when it finds
            // something wrong. The operator gets the report and the warning.
            $status = $exitCode === 0 ? 'ok' : (static::writtenSince($key, $startedAt) ? 'warning' : 'failed');
        } catch (CommandNotFoundException $e) {
            $status = 'missing-command';
            $errorMessage = $e->getMessage();
            Log::channel('seo-reports')->error("The command {$command} is not installed on this site", $context + [
                'exception' => CommandNotFoundException::class,
                'message' => $errorMessage,
            ]);
        } catch (Throwable $e) {
            $status = 'failed';
            $errorMessage = $e->getMessage();
            Log::channel('seo-reports')->error('report run failed', $context + [
                'exception' => get_class($e),
                'message' => $errorMessage,
                'trace' => static::traceFrames($e),
            ]);
        }

        $finishedAt = Carbon::now();
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $finishLevel = match ($status) { 'ok' => 'info', 'warning' => 'warning', default => 'error' };
        Log::channel('seo-reports')->{$finishLevel}('report run finished', $context + [
            'status' => $status,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'output_tail' => mb_substr($output, -4000),
            'file' => static::fileInfo($key),
        ]);

        return [
            'status' => $status,
            'ok' => in_array($status, ['ok', 'warning'], true),
            'message' => static::message($status, $label, $command, $durationMs, $errorMessage, $exitCode),
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'duration_ms' => $durationMs,
            'exit_code' => $exitCode,
            'output_tail' => mb_substr($output, -2000),
            'log_channel' => 'seo-reports',
        ];
    }

    /** "3.2 s", the way the operator reads a duration. */
    protected static function formatSeconds(int $ms): string
    {
        return number_format($ms / 1000, 1).' s';
    }

    /** Whether the report file was (re)written during this run. */
    protected static function writtenSince(string $key, Carbon $startedAt): bool
    {
        $disk = Storage::disk('local');
        $path = SeoStorage::path("reports/{$key}.md");

        return $disk->exists($path) && $disk->lastModified($path) >= $startedAt->getTimestamp() - 1;
    }

    /** The report file's state after the run — same fields fileEntry() reads. */
    protected static function fileInfo(string $key): array
    {
        $disk = Storage::disk('local');
        $path = SeoStorage::path("reports/{$key}.md");
        $exists = $disk->exists($path);

        return [
            'path' => $path,
            'exists' => $exists,
            'size' => $exists ? $disk->size($path) : null,
            'modified_at' => $exists ? Carbon::createFromTimestamp($disk->lastModified($path))->toIso8601String() : null,
        ];
    }

    /** First five frames of the stack, compact enough to read in the log viewer. */
    protected static function traceFrames(Throwable $e): array
    {
        return collect($e->getTrace())
            ->take(5)
            ->map(fn (array $frame) => sprintf(
                '%s:%s %s%s%s()',
                $frame['file'] ?? '[internal]',
                $frame['line'] ?? '?',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? ''
            ))
            ->all();
    }

    protected static function message(string $status, string $label, string $command, int $durationMs, ?string $errorMessage, ?int $exitCode): string
    {
        $seconds = number_format($durationMs / 1000, 1);

        return match ($status) {
            'missing-command' => "Could not run {$label}: the command {$command} is not installed on this site.",
            'warning' => sprintf('%s regenerated in %s, but the command reported problems (exit %d). See what it printed.', $label, static::formatSeconds($durationMs), $exitCode),
            'failed' => "{$label} failed: ".($errorMessage ?? "exited with status {$exitCode}."),
            default => "{$label} regenerated in {$seconds} s.",
        };
    }
}
