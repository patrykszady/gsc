<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use SsSystems\Platform\Telemetry\ClientErrorIngest;
use SsSystems\Platform\Telemetry\Contracts\ClientErrorWriter;

/**
 * Public ingest endpoint for the front-end JS-error beacon — the actual
 * logic lives in `SsSystems\Platform\Telemetry\ClientErrorIngest` (kit
 * 0.14.0, ss-platform-kit/docs/audit-2026-09-27/public-seo-surface.md unit
 * #11's "Option A" mechanical de-dup). This class only wires the kit's
 * `ClientErrorWriter` contract to this site's own `ClientError` model (see
 * `App\Providers\AppServiceProvider`'s binding, gsc's `use BelongsToSite;`
 * tenant scope keeps applying exactly as before) and its own `$logError`
 * closure — gsc writes the full error context to the dedicated
 * `client_errors` daily log channel, UNGUARDED (a channel-resolution
 * failure bubbles up exactly as it did before this move; see
 * `ClientErrorIngest`'s own docblock for why this is a per-site closure
 * rather than one shared logging decision — jpeterson-design has no such
 * channel and wraps its own call in a try/catch instead).
 */
class ClientErrorController extends Controller
{
    public function __construct(
        private readonly ClientErrorWriter $writer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $ingest = new ClientErrorIngest(
            $this->writer,
            logError: fn (array $context) => Log::channel('client_errors')->warning('client.js_error', $context),
        );

        return $ingest->handle($request);
    }
}
