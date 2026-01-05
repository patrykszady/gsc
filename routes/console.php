<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\Project;
use App\Models\Testimonial;
use App\Services\TestimonialProjectTypeClassifier;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('gsc:classify-testimonials
    {--only-missing : Only update rows where project_type is null/empty}
    {--limit= : Limit number of testimonials processed}
    {--dry-run : Print proposed changes without writing to DB}
    {--model= : Override OpenAI model (defaults to services.openai.model)}
', function () {
    $onlyMissing = (bool) $this->option('only-missing');
    $dryRun = (bool) $this->option('dry-run');
    $limit = $this->option('limit');
    $model = $this->option('model');

    $allowedTypes = array_keys(Project::projectTypes());

    $query = Testimonial::query()->orderBy('id');

    if ($onlyMissing) {
        $query->where(function ($q) {
            $q->whereNull('project_type')->orWhere('project_type', '');
        });
    }

    if ($limit !== null && $limit !== '') {
        $query->limit((int) $limit);
    }

    $classifier = app(TestimonialProjectTypeClassifier::class);

    $count = 0;
    $changed = 0;

    $this->info('Allowed project types: '.implode(', ', $allowedTypes));
    if ($dryRun) {
        $this->warn('Dry-run mode: no DB changes will be saved.');
    }

    $query->chunkById(50, function ($rows) use (&$count, &$changed, $classifier, $allowedTypes, $model, $dryRun) {
        foreach ($rows as $t) {
            $count++;

            $suggested = $classifier->classify($t->review_description ?? '', $allowedTypes, $model ?: null);

            if (! $suggested) {
                $this->line("#{$t->id} {$t->reviewer_name}: unable to classify");
                continue;
            }

            $current = $t->project_type;
            if ($current === $suggested) {
                $this->line("#{$t->id} {$t->reviewer_name}: unchanged ({$suggested})");
                continue;
            }

            $this->line("#{$t->id} {$t->reviewer_name}: {$current} -> {$suggested}");

            if (! $dryRun) {
                $t->project_type = $suggested;
                $t->save();
            }

            $changed++;
        }
    });

    $this->newLine();
    $this->info("Processed: {$count}");
    $this->info("Updated: {$changed}".($dryRun ? ' (dry-run)' : ''));
})->purpose('Classify testimonial project_type via OpenAI (with fallback)');
