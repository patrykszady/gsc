<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\AreaServiceContent;
use App\Services\AiContentService;
use Illuminate\Console\Command;

/**
 * Write the copy each service page carries in each town, one (town, service)
 * pair per model call. Pairs that already have copy are skipped unless
 * --force. Rate-limited like seo:generate-area-content.
 */
class GenerateAreaServiceContent extends Command
{
    protected $signature = 'seo:generate-area-service-content
        {--slug= : One town by slug}
        {--service= : One service slug (kitchen-remodeling, bathroom-remodeling, home-remodeling, basement-remodeling, home-additions)}
        {--limit=0 : Stop after this many pairs (0 = all)}
        {--force : Regenerate pairs that already have copy}
        {--dry-run : Show what would be written without saving}';

    protected $description = 'Generate the per-town copy for each service page (kitchen in Western Springs, not Western Springs) via Gemini';

    public function handle(AiContentService $ai): int
    {
        $rpm = (int) config('services.google.gemini_rpm_limit', 6);
        $sleepSeconds = $rpm > 0 ? (int) ceil(60 / $rpm) : 0;
        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $limit = (int) $this->option('limit');

        $services = AreaServiceContent::SERVICES;
        if ($service = trim((string) $this->option('service'))) {
            if (! in_array($service, $services, true)) {
                $this->error('Unknown service. One of: '.implode(', ', $services));

                return self::FAILURE;
            }
            $services = [$service];
        }

        $areas = AreaServed::query()->with('serviceContents')->orderBy('city')
            ->when($this->option('slug'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        $pairs = [];
        foreach ($areas as $area) {
            foreach ($services as $svc) {
                if ($force || $area->serviceContent($svc) === null) {
                    $pairs[] = [$area, $svc];
                }
            }
        }
        if ($limit > 0) {
            $pairs = array_slice($pairs, 0, $limit);
        }

        if ($pairs === []) {
            $this->info('Nothing to do — every requested page already has its copy (use --force to regenerate).');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d page(s) to write. Mode: %s. RPM cap: %d (%ds between calls).', count($pairs), $dry ? 'DRY-RUN' : 'WRITE', $rpm, $sleepSeconds));

        if (! $dry && ! $this->confirm(sprintf('Write AI-generated copy for %d town/service pages?', count($pairs)), false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $ok = 0;
        $fail = 0;

        foreach ($pairs as $i => [$area, $svc]) {
            $this->line('');
            $this->line('['.($i + 1).'/'.count($pairs)."] {$area->city} — {$svc}");

            $content = $ai->generateAreaServiceContent($area, $svc);

            if ($content === null) {
                $fail++;
                $this->warn(' ↳ FAILED: '.($ai->getLastError() ?: 'unknown'));
            } else {
                $this->line('   • intro: '.mb_substr(str_replace(["\n", "\r"], ' ', $content['intro']), 0, 140).'…');
                $this->line('   • faq: '.implode(' / ', array_map(fn ($f) => $f['question'], $content['faq'])));

                if ($dry) {
                    $this->comment('   (dry-run: not saved)');
                } else {
                    AreaServiceContent::updateOrCreate(
                        ['area_served_id' => $area->id, 'service' => $svc],
                        $content + ['model' => (string) config('services.google.gemini_model', 'gemini-2.5-flash'), 'generated_at' => now()],
                    );
                    $this->info('   ✓ saved');
                }
                $ok++;
            }

            if ($sleepSeconds > 0 && $i < count($pairs) - 1) {
                sleep($sleepSeconds);
            }
        }

        $this->line('');
        $this->info("Done. ok={$ok} failed={$fail}");

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
