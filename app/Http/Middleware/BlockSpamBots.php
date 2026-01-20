<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockSpamBots
{
    /**
     * Known bad bot user agents (case-insensitive patterns).
     */
    protected array $blockedUserAgents = [
        'ahrefsbot',
        'semrushbot',
        'dotbot',
        'mj12bot',
        'blexbot',
        'dataforseobot',
        'serpstatbot',
        'bytespider',
        'megaindex',
        'linkdexbot',
        'exabot',
        'yandex',
        'baiduspider',
        'sogou',
        'seznambot',
        'rogerbot',
        'archive.org_bot',
        'seokicks',
        'siteexplorer',
        'aspiegelbot',
        'spambot',
        'nutch',
        'crawler4j',
        'mail.ru',
        '360spider',
        'py-requests', // Often used by scrapers
        'python-urllib',
        'python-requests',
        'libwww-perl',
        'lwp-trivial',
        'wget',
        'httrack',
        'java/',
        // Note: HeadlessChrome removed - used by legitimate tools like Lighthouse, GTmetrix, PageSpeed Insights
    ];

    /**
     * Known spam referrer domains.
     */
    protected array $blockedReferrers = [
        'semalt.com',
        'buttons-for-website.com',
        'darodar.com',
        'econom.co',
        'ilovevitaly.com',
        'priceg.com',
        'savetubevideo.com',
        'screentoolkit.com',
        'videos-for-your-business.com',
        'webmonetizer.net',
        'ranksonic.info',
        'offers.bycontext.com',
        'hundredthousand.xyz',
        'trafficmonetize.org',
        'traffic2money.com',
        'free-share-buttons.com',
        'event-tracking.com',
        'get-free-traffic-now.com',
        'floating-share-buttons.com',
        'traffic2cash.xyz',
        'best-seo-offer.com',
        'guardlink.org',
        'simple-share-buttons.com',
    ];

    /**
     * Suspicious request patterns (path-based).
     */
    protected array $suspiciousPaths = [
        '/wp-login.php',
        '/wp-admin',
        '/xmlrpc.php',
        '/wp-content',
        '/wp-includes',
        '/.env',
        '/.git',
        '/phpmyadmin',
        '/admin.php',
        '/administrator',
        '/config.php',
        '/install.php',
        '/setup.php',
        '/shell.php',
        '/eval-stdin.php',
        '/vendor/phpunit',
        '/solr/',
        '/actuator/',
        '/api/jsonws/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Block by user agent
        $userAgent = strtolower($request->userAgent() ?? '');
        foreach ($this->blockedUserAgents as $blocked) {
            if (str_contains($userAgent, $blocked)) {
                \Log::debug('Blocked bot by user agent', [
                    'ua' => $request->userAgent(),
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                ]);
                return $this->blockResponse();
            }
        }

        // 2. Block empty user agents (almost always bots)
        if (empty($userAgent) && !$request->isMethod('OPTIONS')) {
            \Log::debug('Blocked request with empty user agent', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);
            return $this->blockResponse();
        }

        // 3. Block spam referrers
        $referrer = strtolower($request->header('Referer', ''));
        foreach ($this->blockedReferrers as $blocked) {
            if (str_contains($referrer, $blocked)) {
                \Log::debug('Blocked spam referrer', [
                    'referrer' => $referrer,
                    'ip' => $request->ip(),
                ]);
                return $this->blockResponse();
            }
        }

        // 4. Block suspicious paths (common attack vectors)
        $path = strtolower($request->path());
        foreach ($this->suspiciousPaths as $suspicious) {
            if (str_contains('/' . $path, $suspicious)) {
                // Don't log these - they're extremely common probes
                return $this->notFoundResponse();
            }
        }

        // 5. Block requests with too many query parameters (often SQL injection attempts)
        if (count($request->query()) > 20) {
            \Log::debug('Blocked request with excessive query params', [
                'ip' => $request->ip(),
                'count' => count($request->query()),
            ]);
            return $this->blockResponse();
        }

        return $next($request);
    }

    protected function blockResponse(): Response
    {
        return response('', 403);
    }

    protected function notFoundResponse(): Response
    {
        return response('', 404);
    }
}
