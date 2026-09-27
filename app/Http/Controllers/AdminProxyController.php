<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Support\SiteConfig;
use App\Support\Theme;
use Illuminate\Http\Request;
use SsSystems\Platform\Http\AdminProxyRelay;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transparent relay of /admin/* to the ss-systems central admin — the
 * implementation lives in SsSystems\Platform\Http\AdminProxyRelay (kit
 * 0.13.0, ss-platform-kit/docs/audit-2026-09-27/admin-api-skeleton.md unit
 * #1, ss-systems/CLAUDE.md's "What this app is" for the proxy contract).
 * This site has no admin of its own — the whole editing experience (login,
 * Livewire components, everything under /admin) lives on ss-systems and is
 * served here byte-for-byte. The browser only ever talks to THIS origin;
 * this controller is the only thing that knows ss-systems exists.
 *
 * gsc's one divergence from the shared relay: the down page must still
 * wear the visiting host's brand, via applySiteOverlay() below, bound as
 * the relay's $beforeDown hook — this route runs without ResolveSite (see
 * routes/web.php), so the tenant/theme/config overlay is never applied by
 * the usual middleware.
 *
 * $circuitBreaker: true is NEW here (2026-09-27, kit 0.13.0) — gsc never
 * had this protection before (hive/dawn did); until now, every /admin hit
 * while ss-systems was down took a full outbound-timeout on the single
 * most-probed path on the internet. Called out as the named behaviour
 * change it is (see AdminProxyRelay's own docblock) — same shape hive
 * already ran, now visible as an explicit flag instead of only ever having
 * existed on two of the four sites.
 */
class AdminProxyController extends Controller
{
    public function handle(Request $request, string $path = ''): Response
    {
        return $this->relay()->handle($request, $path);
    }

    protected function relay(): AdminProxyRelay
    {
        return new AdminProxyRelay(
            baseUrl: (string) config('services.ss.url'),
            adminPrefix: (string) config('services.ss.admin_prefix'),
            siteKey: (string) config('services.ss.site_key'),
            serviceSecret: (string) config('services.ss.service_secret'),
            timeout: (int) config('services.ss.timeout', 30),
            connectTimeout: (int) config('services.ss.connect_timeout', 5),
            circuitBreaker: true,
            beforeDown: fn (Request $request) => $this->applySiteOverlay($request),
        );
    }

    /** Bind the tenant for this host, so a view rendered here carries its brand. */
    protected function applySiteOverlay(Request $request): void
    {
        if (app()->bound('site.overlay_applied')) {
            return;
        }

        $site = Site::forDevHost($request->getHost())
            ?? Site::forHost($request->getHost())
            ?? Site::forPreviewHost($request->getHost())
            ?? Site::default();

        Site::setCurrent($site);
        Theme::apply($site);
        SiteConfig::applyRuntime($site);
        app()->instance('site.overlay_applied', true);
    }
}
