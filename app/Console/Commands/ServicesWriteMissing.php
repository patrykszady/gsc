<?php

namespace App\Console\Commands;

use App\Jobs\GenerateServiceContentJob;
use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Queue page copy for every service missing any of it. Mirrors
 * jpeterson-design's AreasWriteMissing (and this app's own
 * areas:write-missing equivalent for AreaServed) — same "any field still
 * empty" catch-up sweep, here for the services table's new content
 * columns. Run once per environment after deploying; safe to re-run.
 */
class ServicesWriteMissing extends Command
{
    protected $signature = 'services:write-missing {--force : Rewrite every service, not just the empty ones}';

    protected $description = 'Draft page copy for every service whose intro is still empty (--force: all of them, replacing what is there)';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        // "Missing" means any generated piece is empty — a service written
        // before the newer sections existed gets just those filled in (the
        // job never touches text that is already there unless --force).
        $services = Service::query()
            ->unless($force, fn ($q) => $q->where(function ($q) {
                foreach (GenerateServiceContentJob::FIELDS as $field) {
                    $q->orWhereNull($field)->orWhere($field, '')->orWhere($field, '[]');
                }
            }))
            ->ordered()
            ->get();

        $queued = 0;
        foreach ($services as $service) {
            if (Cache::has(GenerateServiceContentJob::flagKey($service->id))) {
                $this->line("{$service->name}: already writing, skipped");

                continue;
            }

            GenerateServiceContentJob::launch($service, $force);
            $this->line("{$service->name}: queued");
            $queued++;
        }

        $this->info("{$queued} service(s) queued.");

        return self::SUCCESS;
    }
}
