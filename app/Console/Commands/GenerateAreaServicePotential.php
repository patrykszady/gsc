<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\AreaServiceContent;
use App\Services\AiContentService;
use Illuminate\Console\Command;

/**
 * Fill "What that means for {service} in {town}" (area_service_contents
 * .town_potential) for every (town, service) pair that has its copy but not
 * this fold. One model call per pair, rate-limited like the other generators.
 */
class GenerateAreaServicePotential extends Command
{
    protected $signature = 'seo:generate-area-service-potential
        {--slug= : One town by slug}
        {--service= : One service slug}
        {--limit=0 : Stop after this many pairs (0 = all)}
        {--force : Rewrite pairs that already have the fold}
        {--yes : Skip the confirmation (for the scheduler)}
        {--dry-run : Show the text without saving}';

    protected $description = 'Write the per-service "what the town\'s history means for this trade" fold for each town-service page via Gemini';

    public function handle(AiContentService $ai): int
    {
        $rpm = (int) config('services.google.gemini_rpm_limit', 6);
        $sleepSeconds = $rpm > 0 ? (int) ceil(60 / $rpm) : 0;
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $service = trim((string) $this->option('service'));
        if ($service !== '' && ! in_array($service, AreaServiceContent::SERVICES, true)) {
            $this->error('Unknown service. One of: '.implode(', ', AreaServiceContent::SERVICES));

            return self::FAILURE;
        }

        $rows = AreaServiceContent::query()->with('area')
            ->when($this->option('slug'), fn ($q, $slug) => $q->whereHas('area', fn ($a) => $a->where('slug', $slug)))
            ->when($service !== '', fn ($q) => $q->where('service', $service))
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('town_potential'))
            ->get()
            ->filter(fn (AreaServiceContent $row) => $row->area !== null)
            ->sortBy(fn (AreaServiceContent $row) => $row->area->city.' '.$row->service)
            ->values();
        if ($limit > 0) {
            $rows = $rows->take($limit);
        }

        if ($rows->isEmpty()) {
            $this->info('Nothing to do — every page with its copy has the fold (use --force to rewrite).');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d page(s) to write. Mode: %s. RPM cap: %d (%ds between calls).', $rows->count(), $dry ? 'DRY-RUN' : 'WRITE', $rpm, $sleepSeconds));
        if (! $dry && ! $this->option('yes') && ! $this->confirm(sprintf('Write the fold for %d town/service pages?', $rows->count()), false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $ok = 0;
        $fail = 0;
        foreach ($rows as $i => $row) {
            /** @var AreaServed $area */
            $area = $row->area;
            $this->line('');
            $this->line('['.($i + 1).'/'.$rows->count()."] {$area->city} — {$row->service}");
            $text = $ai->generateAreaServicePotential($area, $row->service);
            if ($text === null) {
                $fail++;
                $this->warn(' ↳ FAILED: '.($ai->getLastError() ?: 'unknown'));
            } else {
                $this->line('   • '.mb_substr(str_replace(["\n", "\r"], ' ', $text), 0, 140).'…');
                if ($dry) {
                    $this->comment('   (dry-run: not saved)');
                } else {
                    $row->forceFill(['town_potential' => $text])->save();
                    $this->info('   ✓ saved');
                }
                $ok++;
            }
            if ($sleepSeconds > 0 && $i < $rows->count() - 1) {
                sleep($sleepSeconds);
            }
        }

        $this->line('');
        $this->info("Done. ok={$ok} failed={$fail}");

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
