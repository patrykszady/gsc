<?php

namespace App\Support\Seo\Inspection;

use App\Services\GoogleSearchConsoleService;
use App\Support\Seo\SearchConsoleProperty;
use Illuminate\Http\Client\ConnectionException;
use SsSystems\Platform\Seo\Inspection\Contracts\UrlInspector;

/**
 * The kit's UrlInspector, wired to this site's existing Search Console
 * service — GoogleSearchConsoleService::inspectUrl() already has the exact
 * shape the interface wants (inspectionResult-or-null, getLastError()), per
 * docs/INSPECTION-SWEEP.md in the kit's source repo.
 *
 * One thing that service does NOT do on its own: catch a network failure.
 * inspectUrl() has no try/catch around its Http call, so a timeout/DNS/
 * connection-refused throws ConnectionException straight through it — and
 * UrlInspectionSweep::run() does not wrap calls to inspect() in a try/catch
 * (see that class's own docblock), the same way this kit's
 * Reports\Contracts\PageFetcher requires of every implementation. The
 * original command's inline HTTP call caught exactly this exception and
 * counted it as a per-URL failure without aborting the sweep; this adapter
 * does the same around the service call, and reports it as a network error
 * (status: null) via lastError() — GoogleSearchConsoleService::getLastError()
 * itself is not updated on this path (the exception happened before it could
 * set $lastError), so this class tracks its own network-error state and
 * prefers it over the service's own last error when set.
 */
final class SearchConsoleUrlInspector implements UrlInspector
{
    /** @var array{status: ?int, message: string}|null */
    private ?array $networkError = null;

    public function __construct(private readonly GoogleSearchConsoleService $service) {}

    public function inspect(string $siteUrl, string $url): ?array
    {
        $this->networkError = null;

        try {
            return $this->service->inspectUrl($siteUrl, $url);
        } catch (ConnectionException $e) {
            $this->networkError = ['status' => null, 'message' => $e->getMessage()];

            return null;
        }
    }

    public function lastError(): ?array
    {
        return $this->networkError ?? $this->service->getLastError();
    }

    /** A stored grant with a refresh token: the same "connected" signal the Platforms card reports. */
    public function isReady(): bool
    {
        return filled($this->service->getStoredToken()?->refresh_token);
    }

    public function siteUrl(): string
    {
        return SearchConsoleProperty::url();
    }
}
