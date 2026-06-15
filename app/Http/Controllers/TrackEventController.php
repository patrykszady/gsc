<?php

namespace App\Http\Controllers;

use App\Models\TrackedEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            'gtag_active' => 'nullable|boolean',
        ]);

        // Drop obvious bots — they shouldn't pollute conversion stats.
        $userAgent = (string) $request->userAgent();
        if ($this->isBot($userAgent)) {
            return response()->json(['ok' => true]);
        }

        $event = TrackedEvent::create([
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

        // Mirror the event to GA4 server-side, but only when the client did NOT
        // already fire it via gtag (gtag only loads for US visitors) — this
        // captures non-US and ad-blocked traffic without double counting.
        if (empty($validated['gtag_active'])) {
            $this->forwardToGa4($event, $request);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Forward a tracked event to GA4 via the Measurement Protocol.
     *
     * Mirrors App\Livewire\ContactSection::sendServerSideAnalytics() so our
     * first-party data and GA4 stay in sync. Never throws — analytics must
     * not affect the ingest response.
     */
    protected function forwardToGa4(TrackedEvent $event, Request $request): void
    {
        $measurementId = config('services.google.measurement_id');
        $apiSecret = config('services.google.measurement_api_secret');

        if (! $measurementId || ! $apiSecret) {
            return;
        }

        // Map our internal event types to GA4 event names (matching the
        // client-side gtag events fired in the app layout).
        $eventName = match ($event->type) {
            TrackedEvent::TYPE_PHONE_CLICK => 'phone_call',
            TrackedEvent::TYPE_EMAIL_CLICK => 'email_click',
            TrackedEvent::TYPE_FORM_SUBMIT => 'generate_lead',
            TrackedEvent::TYPE_CTA_CLICK => 'cta_click',
            default => 'select_content',
        };

        try {
            // Resolve a GA client_id in order of reliability:
            // 1. GA's own _ga cookie, 2. our session id, 3. generated UUID.
            $gaCookie = $request->cookie('_ga');
            if ($gaCookie && preg_match('/GA\d+\.\d+\.(.+)/', $gaCookie, $matches)) {
                $clientId = $matches[1];
            } elseif (session()->has('ga_client_id')) {
                $clientId = session('ga_client_id');
            } else {
                $clientId = (string) Str::uuid();
                session(['ga_client_id' => $clientId]);
            }

            Http::timeout(5)->post("https://www.google-analytics.com/mp/collect?measurement_id={$measurementId}&api_secret={$apiSecret}", [
                'client_id' => $clientId,
                'events' => [
                    [
                        'name' => $eventName,
                        'params' => array_filter([
                            'event_category' => 'engagement',
                            'event_label' => $event->label,
                            'value' => 1,
                            'tracking_method' => 'server_side',
                            'source_channel' => 'first_party_track',
                            'page_location' => $event->page_path ? url($event->page_path) : $request->fullUrl(),
                            'page_referrer' => $event->referrer,
                            'visitor_country' => $event->country,
                            'campaign_source' => $event->utm_source,
                            'user_agent' => $request->userAgent(),
                        ], fn ($v) => $v !== null && $v !== ''),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            // Don't let analytics failures affect the ingest endpoint.
            Log::warning('GA4 forward from track endpoint failed', ['error' => $e->getMessage()]);
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
