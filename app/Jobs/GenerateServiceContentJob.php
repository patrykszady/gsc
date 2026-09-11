<?php

namespace App\Jobs;

use App\Models\Service;
use App\Services\AiContentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fill a new (or thin) service's own page with AI-drafted copy: intro,
 * what_we_do, ideal_for and faq, from
 * AiContentService::generateServiceContent (Gemini). Mirrors
 * GenerateAreaContentJob's flagKey/launch/handle shape and this site's
 * "generating" cache flag the admin polls — same backbone ported
 * file-for-file from jpeterson-design's identical job on its own Service
 * model. Existing text is kept unless $force.
 */
class GenerateServiceContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Fields this job will write, in page order. */
    public const FIELDS = ['intro', 'what_we_do', 'ideal_for', 'faq'];

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $serviceId, public bool $force = false)
    {
        $this->onQueue('ai-content');
    }

    public static function flagKey(int $serviceId): string
    {
        return "services.generating.{$serviceId}";
    }

    /**
     * Mark the service as generating (for the admin poll) and dispatch the
     * job. Not named queue(): the bus treats a queue() method on a job as
     * a custom dispatch hook.
     */
    public static function launch(Service $service, bool $force = false): void
    {
        Cache::put(self::flagKey($service->id), ['queued_at' => now()->toDateTimeString()], now()->addMinutes(20));
        // After the response: the admin expects the 202 back at once and
        // then polls the generating flag, same as GenerateAreaContentJob.
        self::dispatchAfterResponse($service->id, $force);
    }

    public function handle(AiContentService $ai): void
    {
        $service = Service::find($this->serviceId);
        if (! $service) {
            Cache::forget(self::flagKey($this->serviceId));

            return;
        }

        try {
            $missing = array_values(array_filter(self::FIELDS, fn ($f) => $this->force || ($f === 'faq' ? $service->faqItems() === [] : blank($service->{$f}))));

            if ($missing !== []) {
                $content = $ai->generateServiceContent($service);
                if ($content === null) {
                    Log::warning('services: content generation failed', ['service' => $service->slug, 'error' => $ai->getLastError()]);
                    Cache::put(self::flagKey($service->id), ['error' => $ai->getLastError() ?: 'Generation failed.'], now()->addMinutes(20));

                    return;
                }
                $updates = [];
                foreach ($missing as $f) {
                    if (! empty($content[$f])) {
                        $updates[$f] = $content[$f];
                    }
                }
                if ($updates !== []) {
                    $service->fill($updates)->save();
                }
            }
            Cache::forget(self::flagKey($service->id));
            Log::info('services: content generated', ['service' => $service->slug, 'fields' => $missing]);
        } catch (\Throwable $e) {
            Cache::put(self::flagKey($service->id), ['error' => mb_substr($e->getMessage(), 0, 200)], now()->addMinutes(20));
            throw $e;
        }
    }
}
