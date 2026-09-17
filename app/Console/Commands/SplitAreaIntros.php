<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Services\AiContentService;
use Illuminate\Console\Command;

/**
 * Sort each town's long copy into the lead and the two accordion folds the
 * town page shows ("how the town was built" / "what that means for
 * remodeling"). A town whose copy has not changed since its split was made
 * is skipped, so the daily run only touches towns edited in admin.
 */
class SplitAreaIntros extends Command
{
    protected $signature = 'seo:split-area-intros
        {--slug= : One town by slug}
        {--limit=0 : Stop after this many towns (0 = all)}
        {--force : Re-split towns whose split is current}
        {--yes : Skip the confirmation (for the scheduler)}
        {--dry-run : Show the split without saving}';

    protected $description = 'Sort each town\'s long copy into a lead and the history / remodeling-potential folds via Gemini';

    public function handle(AiContentService $ai): int
    {
        $rpm = (int) config('services.google.gemini_rpm_limit', 6);
        $sleepSeconds = $rpm > 0 ? (int) ceil(60 / $rpm) : 0;
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $areas = AreaServed::query()->orderBy('city')
            ->when($this->option('slug'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get()
            ->filter(fn (AreaServed $a) => filled($a->local_intro) && ($this->option('force') || ! $a->hasCurrentIntroSplit()))
            ->values();
        if ($limit > 0) {
            $areas = $areas->take($limit);
        }

        if ($areas->isEmpty()) {
            $this->info('Nothing to do — every town\'s split matches its copy (use --force to redo).');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d town(s) to split. Mode: %s. RPM cap: %d (%ds between calls).', $areas->count(), $dry ? 'DRY-RUN' : 'WRITE', $rpm, $sleepSeconds));
        if (! $dry && ! $this->option('yes') && ! $this->confirm(sprintf('Split the copy of %d town(s)?', $areas->count()), false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $ok = 0;
        $fail = 0;
        foreach ($areas as $i => $area) {
            $this->line('');
            $this->line('['.($i + 1).'/'.$areas->count()."] {$area->city}");
            $split = $ai->splitAreaIntro($area);
            if ($split === null) {
                $fail++;
                $this->warn(' ↳ FAILED: '.($ai->getLastError() ?: 'unknown'));
            } else {
                $this->line(sprintf('   • lead %d / history %d / potential %d words', str_word_count($split['lead']), str_word_count($split['history']), str_word_count($split['potential'])));
                $this->line('   • history: '.mb_substr(str_replace(["\n", "\r"], ' ', $split['history']), 0, 120).'…');
                $this->line('   • potential: '.mb_substr(str_replace(["\n", "\r"], ' ', $split['potential']), 0, 120).'…');
                if ($dry) {
                    $this->comment('   (dry-run: not saved)');
                } else {
                    $area->forceFill(['intro_folds' => $split + [
                        'source_hash' => AreaServed::introHash($area->local_intro),
                        'model' => (string) config('services.google.gemini_model', 'gemini-2.5-flash'),
                        'generated_at' => now()->toIso8601String(),
                    ]])->save();
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
