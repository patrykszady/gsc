<?php

namespace App\Support;

use App\Jobs\RefreshPublicFeedsJob;

/**
 * Keeps the site's generated files — sitemap.xml, image-sitemap.xml,
 * llms.txt, llms-full.txt — in step with the content: any save or delete
 * of something those files list asks for one refresh after the response,
 * however many saves the request made. The same mechanism as
 * jpeterson-design's; see AppServiceProvider::boot for the models that
 * call it and RefreshPublicFeedsJob for what runs.
 */
class PublicFeeds
{
    protected static bool $queued = false;

    public static function refreshSoon(): void
    {
        if (static::$queued || app()->bound('public-feeds.generating') || (app()->runningUnitTests() && ! app()->bound('public-feeds.in-tests'))) {
            return;
        }
        static::$queued = true;
        RefreshPublicFeedsJob::dispatchAfterResponse();
    }

    /** Reset between requests in long-lived workers and tests. */
    public static function reset(): void
    {
        static::$queued = false;
    }

    /** Tests run with the refresh switched off so they never write into public/; one that asserts it opts in. */
    public static function enableForTests(): void
    {
        app()->instance('public-feeds.in-tests', true);
    }
}
