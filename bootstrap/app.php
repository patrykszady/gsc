<?php

use App\Http\Middleware\CacheStaticAssets;
use App\Http\Middleware\CaptureUtmParameters;
use App\Http\Middleware\DetectCountry;
use App\Http\Middleware\RedirectLegacyUrls;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackDomainSource;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/admin/login');
        
        // Bot blocking now handled by Cloudflare WAF + Bot Fight Mode
        
        // SEO: Track domain source for analytics, handle legacy redirects, cache static assets, and add security headers
        // DetectCountry: Uses Cloudflare CF-IPCountry header for geo-based features (GA only for US, visible Turnstile for non-US)
        $middleware->web(append: [
            DetectCountry::class,
            TrackDomainSource::class,
            RedirectLegacyUrls::class,
            CacheStaticAssets::class,
            CaptureUtmParameters::class,
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
