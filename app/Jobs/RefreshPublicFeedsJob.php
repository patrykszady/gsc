<?php

namespace App\Jobs;

use Hszope\LaravelAigeo\Modules\LlmsTxt\LlmsTxtGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Regenerates everything a crawler reads from a file: the sitemap, the
 * image sitemap and the two llms files. Queued by App\Support\PublicFeeds
 * after any change to listed content; the scheduler still runs the same
 * commands nightly as a safety net. Each command is its own try, so one
 * failing never stops the others.
 */
class RefreshPublicFeedsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public const COMMANDS = [
        'sitemap:generate' => [],
        'seo:image-sitemap-build' => [],
        'geo:llms-txt' => [],
        'geo:llms-txt --full' => ['--full' => true],
    ];

    public function handle(): void
    {
        app()->instance('public-feeds.generating', true);

        try {
            // The geo package caches its rendered llms text for an hour
            // (LlmsTxtGenerator::generate → Cache::remember), so without
            // this a regeneration right after a content change — or a
            // deploy — would write the previous hour's text back to disk.
            try {
                app(LlmsTxtGenerator::class)->bust();
            } catch (\Throwable $e) {
                Log::warning('public feeds: could not bust the llms cache', ['error' => $e->getMessage()]);
            }

            foreach (self::COMMANDS as $label => $options) {
                $command = explode(' ', $label)[0];
                try {
                    Artisan::call($command, $options);
                } catch (\Throwable $e) {
                    Log::warning("public feeds: {$label} failed", ['error' => $e->getMessage()]);
                }
            }
        } finally {
            app()->forgetInstance('public-feeds.generating');
        }
    }
}
