<?php

use App\Http\Middleware\BlockSpamBots;
use App\Http\Middleware\CacheStaticAssets;
use App\Http\Middleware\CaptureUtmParameters;
use App\Http\Middleware\RedirectLegacyUrls;
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
        
        // Block spam bots and malicious crawlers early
        $middleware->web(prepend: [
            BlockSpamBots::class,
        ]);
        
        // SEO: Track domain source for analytics, handle legacy redirects, and cache static assets
        $middleware->web(append: [
            TrackDomainSource::class,
            RedirectLegacyUrls::class,
            CacheStaticAssets::class,
            CaptureUtmParameters::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
