<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Services\AiContentService;
use Illuminate\Console\Command;

/**
 * Generate unique per-city content (intro, local_intro, landmarks, permit_notes)
 * for AreaServed rows via Gemini, so each /areas-served/{city} page differentiates
 * itself from the others.
 *
 * Usage:
 *   php artisan seo:generate-area-content --dry-run                # preview all empty cities
 *   php artisan seo:generate-area-content --slug=palatine          # one city, write to DB
 *   php artisan seo:generate-area-content --limit=10               # 10 empty cities, write
 *   php artisan seo:generate-area-content --force                  # overwrite existing content
 *   php artisan seo:generate-area-content --only=intro,landmarks   # only generate these fields
 */
class GenerateAreaContent extends Command
{
    protected $signature = 'seo:generate-area-content
        {--slug= : Restrict to a single city slug}
        {--limit=0 : Max number of cities to process (0 = no limit)}
        {--dry-run : Print generated content but do not save}
        {--force : Overwrite existing non-empty fields}
        {--only= : Comma-separated list of fields to keep (intro,local_intro,landmarks,permit_notes)}
        {--deepen : Expand the existing local_intro to ~2,000 chars instead of regenerating (implies local_intro only)}';

    protected $description = 'Generate unique per-city SEO content for AreaServed pages via Gemini.';

    public function handle(AiContentService $service): int
    {
        $rpm = (int) config('services.google.gemini_rpm_limit', 6);
        $sleepSeconds = $rpm > 0 ? (int) ceil(60 / $rpm) : 0;

        $query = AreaServed::query()->orderBy('city');
        if ($slug = $this->option('slug')) {
            $query->where('slug', $slug);
        }

        $allowedFields = ['intro', 'local_intro', 'landmarks', 'permit_notes'];
        $onlyOpt = trim((string) $this->option('only'));
        $onlyFields = $onlyOpt === ''
            ? $allowedFields
            : array_values(array_intersect($allowedFields, array_map('trim', explode(',', $onlyOpt))));

        if (empty($onlyFields)) {
            $this->error('No valid fields in --only. Allowed: ' . implode(',', $allowedFields));
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $deepen = (bool) $this->option('deepen');

        if ($deepen) {
            return $this->deepen($service, $query->get(), $limit, $dry, $sleepSeconds);
        }

        $areas = $query->get();

        // Filter out cities that already have all requested fields filled (unless --force).
        if (! $force) {
            $areas = $areas->filter(function (AreaServed $a) use ($onlyFields) {
                foreach ($onlyFields as $f) {
                    if (blank($a->{$f})) {
                        return true;
                    }
                }
                return false;
            })->values();
        }

        if ($limit > 0) {
            $areas = $areas->take($limit);
        }

        if ($areas->isEmpty()) {
            $this->info('Nothing to do — all matching areas already have content (use --force to regenerate).');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Processing %d area(s). Fields: %s. Mode: %s. RPM cap: %d (%ds delay).',
            $areas->count(),
            implode(',', $onlyFields),
            $dry ? 'DRY-RUN' : 'WRITE',
            $rpm,
            $sleepSeconds,
        ));

        // Production safety: require confirmation when writing to DB (unless --no-interaction).
        if (! $dry && ! $this->confirm(
            sprintf('Write AI-generated content to %d areas in the DB?', $areas->count()),
            false
        )) {
            $this->warn('Aborted.');
            return self::SUCCESS;
        }

        $ok = 0;
        $fail = 0;

        foreach ($areas as $i => $area) {
            $this->line('');
            $this->line("[" . ($i + 1) . "/{$areas->count()}] {$area->city} ({$area->slug})");

            $content = $service->generateAreaContent($area);
            if ($content === null) {
                $fail++;
                $this->warn(' ↳ FAILED: ' . ($service->getLastError() ?: 'unknown'));
                if ($sleepSeconds > 0 && $i < $areas->count() - 1) {
                    sleep($sleepSeconds);
                }
                continue;
            }

            $updates = [];
            foreach ($onlyFields as $f) {
                if (! $force && filled($area->{$f})) {
                    $this->line("   • {$f}: kept existing");
                    continue;
                }
                $updates[$f] = $content[$f];
                $preview = mb_substr(str_replace(["\n", "\r"], ' ', $content[$f]), 0, 140);
                $this->line("   • {$f}: {$preview}" . (mb_strlen($content[$f]) > 140 ? '…' : ''));
            }

            if (! $dry && ! empty($updates)) {
                $area->fill($updates)->save();
                $this->info('   ✓ saved');
            } elseif ($dry) {
                $this->comment('   (dry-run: not saved)');
            }

            $ok++;

            if ($sleepSeconds > 0 && $i < $areas->count() - 1) {
                sleep($sleepSeconds);
            }
        }

        $this->line('');
        $this->info("Done. ok={$ok} failed={$fail}");
        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * --deepen: expand existing local_intro copy rather than regenerating it.
     * Only touches areas that HAVE a local_intro (deepening nothing is just
     * generation — use the normal mode for that), and the service refuses any
     * result shorter than the original.
     */
    private function deepen(AiContentService $service, $areas, int $limit, bool $dry, int $sleepSeconds): int
    {
        $areas = $areas->filter(fn (AreaServed $a) => filled($a->local_intro))->values();
        if ($limit > 0) {
            $areas = $areas->take($limit);
        }

        if ($areas->isEmpty()) {
            $this->info('Nothing to deepen — no matching areas with existing local_intro.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Deepening local_intro for %d area(s). Mode: %s.', $areas->count(), $dry ? 'DRY-RUN' : 'WRITE'));

        if (! $dry && ! $this->confirm(sprintf('Overwrite local_intro on %d area(s) with expanded copy?', $areas->count()), false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $ok = 0;
        $fail = 0;

        foreach ($areas as $i => $area) {
            $before = mb_strlen((string) $area->local_intro);
            $this->line('');
            $this->line('[' . ($i + 1) . "/{$areas->count()}] {$area->city} ({$area->slug}) — {$before} chars");

            $text = $service->deepenAreaLocalIntro($area);
            if ($text === null) {
                $fail++;
                $this->warn(' ↳ FAILED: ' . ($service->getLastError() ?: 'unknown'));
            } else {
                $this->line('   • local_intro: ' . mb_substr(str_replace(["\n", "\r"], ' ', $text), 0, 140) . '…');
                $this->line('   • ' . $before . ' → ' . mb_strlen($text) . ' chars');

                if ($dry) {
                    $this->comment('   (dry-run: not saved)');
                } else {
                    $area->forceFill(['local_intro' => $text])->save();
                    $this->info('   ✓ saved');
                }
                $ok++;
            }

            if ($sleepSeconds > 0 && $i < $areas->count() - 1) {
                sleep($sleepSeconds);
            }
        }

        $this->line('');
        $this->info("Done. ok={$ok} failed={$fail}");

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
