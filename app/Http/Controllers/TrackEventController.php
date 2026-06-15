<?php

namespace App\Http\Controllers;

use App\Models\TrackedEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class TrackEventController extends Controller
{
    /**
     * Lightweight first-party analytics ingest endpoint.
     *
     * Receives beacon/fetch events from the front-end (phone clicks, email
     * clicks, form submissions, CTA clicks) and stores them so the data is
     * visible in /admin even when Google Analytics is blocked.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // Per-IP rate limit to keep the endpoint from being abused.
        $key = 'track-event:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 120)) {
            return response()->json(['ok' => false], 429);
        }
        RateLimiter::hit($key, 60);

        $validated = $request->validate([
            'type' => 'required|string|in:' . implode(',', TrackedEvent::allowedTypes()),
            'label' => 'nullable|string|max:255',
            'page_path' => 'nullable|string|max:255',
            'referrer' => 'nullable|string|max:255',
            'session_id' => 'nullable|string|max:64',
        ]);

        // Drop obvious bots — they shouldn't pollute conversion stats.
        $userAgent = (string) $request->userAgent();
        if ($this->isBot($userAgent)) {
            return response()->json(['ok' => true]);
        }

        TrackedEvent::create([
            'type' => $validated['type'],
            'label' => $validated['label'] ?? null,
            'page_path' => $validated['page_path'] ?? null,
            'referrer' => $validated['referrer'] ?? null,
            'session_id' => $validated['session_id'] ?? null,
            'country' => session('visitor_country'),
            'utm_source' => session('utm_source'),
            'utm_medium' => session('utm_medium'),
            'utm_campaign' => session('utm_campaign'),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit($userAgent, 500, ''),
        ]);

        return response()->json(['ok' => true]);
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
