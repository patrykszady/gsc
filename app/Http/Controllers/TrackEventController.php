<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SsSystems\Platform\Telemetry\Contracts\TrackedEventWriter;
use SsSystems\Platform\Telemetry\TrackEventIngest;

/**
 * Public ingest endpoint for first-party analytics (phone/email/form/CTA
 * clicks) — the actual logic lives in `SsSystems\Platform\Telemetry\
 * TrackEventIngest` (kit 0.14.0, ss-platform-kit/docs/audit-2026-09-27/
 * public-seo-surface.md unit #11's "Option A" mechanical de-dup, byte-
 * identical to jpeterson-design's own controller before this move). This
 * class only wires the kit's `TrackedEventWriter` contract to this site's
 * own `TrackedEvent` model (see `App\Providers\AppServiceProvider`'s
 * binding), so gsc's `use BelongsToSite;` tenant scope keeps applying
 * exactly as before.
 */
class TrackEventController extends Controller
{
    public function __construct(
        private readonly TrackedEventWriter $writer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return (new TrackEventIngest($this->writer))->handle($request);
    }
}
