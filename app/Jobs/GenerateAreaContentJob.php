<?php

namespace App\Jobs;

use App\Models\AreaServed;
use App\Models\Site;
use App\Services\AiContentService;
use App\Support\Areas\TownCatalog;
use App\Support\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fill a new (or thin) service-area page: coordinates from the town
 * catalog when missing, then the unique-content fields from Gemini (intro,
 * local intro, landmarks, neighborhoods, popular projects, how we work,
 * faq, permit notes — see AiContentService::generateAreaContent).
 * Existing text is kept unless $force. The "generating" flag the admin
 * polls lives in the cache.
 */
class GenerateAreaContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $areaId, public bool $force = false, public ?int $siteId = null)
    {
        $this->siteId = $siteId ?? Site::current()?->id;
        $this->onQueue('ai-content');
    }

    public static function flagKey(int $areaId): string
    {
        return "areas.generating.{$areaId}";
    }

    /** Mark the area as generating (for the admin) and dispatch the job. (Not named queue(): the bus treats a queue() method on a job as a custom dispatch hook.) */
    public static function launch(AreaServed $area, bool $force = false): void
    {
        Cache::put(self::flagKey($area->id), ['queued_at' => now()->toDateTimeString()], now()->addMinutes(20));
        self::dispatch($area->id, $force);
    }

    public function handle(AiContentService $ai): void
    {
        $run = function () use ($ai): void {
            $area = AreaServed::find($this->areaId);
            if (! $area) {
                Cache::forget(self::flagKey($this->areaId));

                return;
            }
            try {
                if ($area->latitude === null || $area->longitude === null) {
                    if ($town = TownCatalog::find((string) $area->city)) {
                        $area->forceFill(['latitude' => $town['lat'], 'longitude' => $town['lng']])->save();
                    }
                }
                $fields = ['intro', 'local_intro', 'landmarks', 'neighborhoods', 'popular_projects', 'how_we_work', 'faq', 'permit_notes'];
                $missing = array_values(array_filter($fields, fn ($f) => $this->force || ($f === 'faq' ? $area->faqItems() === [] : blank($area->{$f}))));
                if ($missing !== []) {
                    $content = $ai->generateAreaContent($area);
                    if ($content === null) {
                        Log::warning('areas: content generation failed', ['area' => $area->slug, 'error' => $ai->getLastError()]);
                        Cache::put(self::flagKey($area->id), ['error' => $ai->getLastError() ?: 'Generation failed.'], now()->addMinutes(20));

                        return;
                    }
                    $updates = [];
                    foreach ($missing as $f) {
                        if (! empty($content[$f])) {
                            $updates[$f] = $content[$f];
                        }
                    }
                    if ($updates !== []) {
                        $area->fill($updates)->save();
                    }
                }
                Cache::forget(self::flagKey($area->id));
                Log::info('areas: content generated', ['area' => $area->slug, 'fields' => $missing]);
            } catch (\Throwable $e) {
                Cache::put(self::flagKey($area->id), ['error' => mb_substr($e->getMessage(), 0, 200)], now()->addMinutes(20));
                throw $e;
            }
        };

        $site = $this->siteId ? Site::find($this->siteId) : null;
        $site ? Tenancy::for($site, $run) : $run();
    }
}
