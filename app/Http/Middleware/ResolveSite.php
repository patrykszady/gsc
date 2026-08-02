<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Support\SiteConfig;
use App\Support\Theme;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class ResolveSite
{
    /**
     * Resolve the tenant for this request and bind it as Site::current(), so
     * everything downstream (themes, scoped queries, per-site config) can
     * rely on it.
     *
     * Resolution order — the HOST decides, locally exactly as in production:
     *  1. {slug}.localhost / {slug}.test  — local only, inactive sites included.
     *  2. Host match against sites.hosts.
     *  3. ?site={slug} — local only, and ONLY when no host matched, i.e. bare
     *     127.0.0.1. Also pins the choice into the session (see below).
     *  4. The session pin from a previous ?site=, under the same conditions.
     *  5. Default site (config sites.default) — unknown hosts, console, tests.
     *
     * Why the host and not a query parameter: nothing propagates a query
     * string. Root-relative hrefs drop it, wire:navigate fetches the literal
     * href, Livewire's update endpoint is emitted with no query at all, and
     * 301s rebuild the URL without it. The host survives every one of those
     * for free, which is why previewing on {slug}.localhost:8003 is the
     * supported path and ?site= is only a compatibility entry point.
     *
     * Steps 3 and 4 cannot fire in production twice over: they are gated on
     * the local environment AND on no host having matched, and in production
     * every real request matches at step 2.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($redirect = $this->canonicalise($request)) {
            return $redirect;
        }

        $this->useBuiltAssetsOffMachine($request);

        [$site, $via] = $this->resolve($request);

        Site::setCurrent($site);
        Theme::apply($site);
        SiteConfig::applyRuntime($site);

        // Tells the exception renderer the overlay is already in place, so an
        // error thrown from a MATCHED route does not apply the theme twice.
        app()->instance('site.overlay_applied', true);

        if (app()->environment('local')) {
            // Read back by the dev site bar and /_sites, so "which tenant am
            // I looking at, and why" is answerable without adding a dd().
            app()->instance('site.resolved_via', $via);
        }

        return $next($request);
    }

    /**
     * Serve BUILT assets to anyone who is not browsing from this machine.
     *
     * `npm run dev` writes public/hot, and from then on Laravel emits every
     * asset as http://127.0.0.1:5173/… — which is correct locally and useless
     * everywhere else. On a tunnel host that URL is both mixed content on an
     * HTTPS page and a pointer at the developer's own loopback, so the client
     * preview renders with no CSS at all.
     *
     * Pointing Vite at a hot file that cannot exist makes isRunningHot() false
     * for that request only, so it falls back to the build manifest. Local
     * hosts keep HMR; remote hosts get the built bundle. Nothing to remember,
     * and `npm run dev` can no longer break the client's preview URL.
     *
     * Harmless in production, where no hot file exists in the first place.
     */
    protected function useBuiltAssetsOffMachine(Request $request): void
    {
        $host = strtolower($request->getHost());

        $isThisMachine = in_array($host, ['127.0.0.1', '::1', 'localhost'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test');

        if ($isThisMachine) {
            return;
        }

        Vite::useHotFile(storage_path('framework/vite-hot-disabled-for-remote-hosts'));
    }

    /**
     * @return array{0: Site, 1: string} the tenant and how it was chosen
     */
    protected function resolve(Request $request): array
    {
        $host = $request->getHost();

        if ($site = Site::forDevHost($host)) {
            return [$site, "dev host {$host}"];
        }

        if ($site = Site::forHost($host)) {
            return [$site, "host {$host}"];
        }

        // A real hostname pointed at a site that has not launched. Explicit
        // opt-in data, so it cannot widen resolution by accident, and the
        // marker below forces noindex on the response.
        if ($site = Site::forPreviewHost($host)) {
            app()->instance('site.preview_host', true);

            return [$site, "preview host {$host}" . ($site->is_active ? '' : ' (site is in build)')];
        }

        if (app()->environment('local')) {
            // Inactive sites included: a theme has to be previewable before launch.
            if ($slug = $request->query('site')) {
                if ($site = Site::query()->where('slug', $slug)->first()) {
                    // Pin it. Without this the next click — or the next
                    // Livewire POST, which carries no query string — would
                    // silently fall through to the default site, which is the
                    // whole reason ?site= felt broken.
                    $request->session()->put('preview.site', $site->slug);

                    return [$site, "?site={$site->slug} (pinned to this browser session)"];
                }

                return [Site::default(), "?site={$slug} matched no site — using default"];
            }

            if ($slug = $request->session()->get('preview.site')) {
                if ($site = Site::query()->where('slug', $slug)->first()) {
                    return [$site, "session pin {$slug} — clear it at /_sites"];
                }
            }
        }

        return [Site::default(), "default — no host matched {$host}"];
    }

    /**
     * Send ?site=X on a dev host to X's own dev host, so the address bar never
     * disagrees with what is rendering.
     *
     * Only fires on the genuine contradiction (a dev host AND a ?site=). Bare
     * 127.0.0.1/?site= is deliberately left to render in place: WSL's resolver
     * does not know *.localhost, so redirecting there would break `curl -L`
     * and any script or agent driving the app from the Linux side.
     */
    protected function canonicalise(Request $request): ?Response
    {
        if (! $request->isMethod('GET') || ! $request->query('site')) {
            return null;
        }

        if (! Site::forDevHost($request->getHost())) {
            return null;
        }

        $target = Site::query()->where('slug', $request->query('site'))->first();

        if (! $target) {
            return null;
        }

        $query = $request->query();
        unset($query['site']);

        // SERVER_PORT before getPort(): getPort() reads the Host header first
        // and falls back to 80 when it carries no port. Browsers always send
        // "host:8003", but curl and scripts do not — and redirecting them to
        // port 80 sends them nowhere.
        $port = (int) ($request->server('SERVER_PORT') ?: $request->getPort());

        return redirect()->away(
            'http://' . $target->devHost() . ($port && $port !== 80 ? ':' . $port : '')
            . $request->getPathInfo()
            . ($query ? '?' . http_build_query($query) : '')
        );
    }
}
