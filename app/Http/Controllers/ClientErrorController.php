<?php

namespace App\Http\Controllers;

use App\Models\ClientError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ClientErrorController extends Controller
{
    /**
     * Ingest front-end JavaScript errors from the client beacon.
     *
     * The Microsoft Clarity Data Export API only returns an aggregate
     * ScriptErrorCount — never the message or stack trace. This endpoint
     * captures the actual error text (window.onerror + unhandledrejection)
     * to the dedicated `client_errors` log channel so regressions are
     * diagnosable from /log-viewer instead of just "errors went up".
     *
     * Designed to never throw: telemetry must not affect the visitor.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // Per-IP rate limit. Bursty error storms (e.g. an infinite loop) are
        // deduped client-side, but cap server-side too as a backstop.
        $key = 'client-error:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 30)) {
            return response()->json(['ok' => false], 429);
        }
        RateLimiter::hit($key, 60);

        // Bots/crawlers and headless audit tools throw plenty of benign JS
        // errors that would pollute the channel. Drop them silently.
        $userAgent = (string) $request->userAgent();
        if ($this->isBot($userAgent)) {
            return response()->json(['ok' => true]);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:500',
            'source' => 'nullable|string|max:255',
            'line' => 'nullable|integer',
            'column' => 'nullable|integer',
            'stack' => 'nullable|string|max:2000',
            'kind' => 'nullable|string|max:32',
            'page_path' => 'nullable|string|max:255',
        ]);

        $kind = $validated['kind'] ?? 'error';
        $message = $validated['message'];
        $source = $validated['source'] ?? null;
        $line = $validated['line'] ?? null;
        $column = $validated['column'] ?? null;
        $stack = isset($validated['stack']) ? Str::limit($validated['stack'], 2000, '') : null;
        $pagePath = $validated['page_path'] ?? null;
        $shortUa = Str::limit($userAgent, 300, '');

        // Preserve the full error in the dedicated log channel (viewable in
        // /log-viewer) for forensic detail and grep-ability.
        Log::channel('client_errors')->warning('client.js_error', [
            'message' => $message,
            'kind' => $kind,
            'source' => $source,
            'line' => $line,
            'column' => $column,
            'page_path' => $pagePath,
            'stack' => $stack,
            'referrer' => Str::limit((string) $request->headers->get('referer', ''), 255, ''),
            'user_agent' => $shortUa,
            'ip' => $request->ip(),
        ]);

        // Aggregate into the DB so the /admin dashboard can show a deduped,
        // ranked list (one row per unique error, with an occurrence count).
        $this->persist($kind, $message, $source, $line, $column, $stack, $pagePath, $shortUa);

        return response()->json(['ok' => true]);
    }

    /**
     * Upsert an aggregated client-error row keyed by a stable fingerprint.
     * Never throws — telemetry must not affect the visitor's response.
     */
    protected function persist(
        string $kind,
        string $message,
        ?string $source,
        ?int $line,
        ?int $column,
        ?string $stack,
        ?string $pagePath,
        ?string $userAgent,
    ): void {
        try {
            $fingerprint = hash('sha256', $kind . '|' . $message . '|' . ($source ?? '') . '|' . ($line ?? ''));
            $now = now();

            $existing = ClientError::where('fingerprint', $fingerprint)->first();

            if ($existing) {
                $existing->forceFill([
                    'occurrences' => $existing->occurrences + 1,
                    'last_seen_at' => $now,
                    // Refresh the latest sample context.
                    'stack' => $stack ?? $existing->stack,
                    'page_path' => $pagePath ?? $existing->page_path,
                    'user_agent' => $userAgent ?? $existing->user_agent,
                    // A fresh occurrence reopens a previously-resolved error.
                    'resolved_at' => null,
                ])->save();

                return;
            }

            ClientError::create([
                'fingerprint' => $fingerprint,
                'kind' => $kind,
                'message' => $message,
                'source' => $source,
                'line' => $line,
                'column' => $column,
                'stack' => $stack,
                'page_path' => $pagePath,
                'user_agent' => $userAgent,
                'occurrences' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ClientError persist failed', ['error' => $e->getMessage()]);
        }
    }

    protected function isBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return true;
        }

        return (bool) preg_match(
            '/bot|crawler|spider|crawling|facebookexternalhit|slurp|bingpreview|headless|lighthouse|gtmetrix|pingdom/i',
            $userAgent
        );
    }
}
