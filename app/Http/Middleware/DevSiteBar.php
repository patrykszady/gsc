<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Support\DevSites;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Local-only: stamp every response with the tenant that served it, and inject
 * a switcher into HTML responses.
 *
 * Injected by middleware rather than added to a Blade layout because the
 * themes share no markup at all — themes/jpeterson's layout shares no markup with gsc's and is
 * just {{ $slot }}, themes/jpeterson brings its own head, header and footer
 * and includes no shared partial, and resources/views/services.blade.php is a
 * standalone document. A partial would have to be pasted into each one, and
 * would be missing from exactly the theme being debugged.
 *
 * The X-Dev-Site header matters as much as the bar: it is the only way to see
 * which tenant handled a POST to the Livewire update endpoint, a redirect, or
 * a JSON/XML response — none of which have a body to inject into.
 */
class DevSiteBar
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Belt and braces: this middleware is only registered in local, and
        // this returns early if that ever changes.
        if (! app()->environment('local')) {
            return $response;
        }

        $response->headers->set('X-Dev-Site', Site::current()->slug);
        $response->headers->set('X-Dev-Site-Via', DevSites::resolvedVia());

        if (! $this->shouldInject($request, $response)) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content) || ($at = strripos($content, '</body>')) === false) {
            return $response;
        }

        $bar = view('dev.site-bar', [
            'sites' => DevSites::register($request->getPathInfo()),
            'site' => Site::current(),
            'via' => DevSites::resolvedVia(),
            'viewsUsed' => DevSites::viewsUsed(),
            'adminSite' => $request->route('site'),
            'back' => $request->fullUrl(),
        ])->render();

        $response->setContent(substr_replace($content, $bar, $at, 0));

        return $response;
    }

    /**
     * Inject only into real HTML documents the developer is looking at.
     *
     * Livewire updates are excluded because their payload is a JSON envelope
     * of component HTML — injecting there would splice the bar into a
     * component's morph target.
     */
    protected function shouldInject(Request $request, Response $response): bool
    {
        // NEVER on a preview host. Those are the URLs a client is sent to look
        // at their own site in progress, and the bar names every other tenant
        // on the platform and links to them. The X-Dev-Site headers above
        // still go out — invisible to the visitor, useful to us.
        if (app()->bound('site.preview_host')) {
            return false;
        }

        return ! $response instanceof StreamedResponse
            && ! $response instanceof BinaryFileResponse
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            && $request->query('_bar') !== '0'
            && $request->cookie('dev_bar') !== 'off'
            && ! $request->hasHeader('X-Livewire')
            && ! $request->ajax();
    }
}
