<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Project;
use App\Models\Testimonial;
use App\Services\TestimonialProjectTypeClassifier;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule sitemap regeneration daily
Schedule::command('sitemap:generate')->daily();

// Google Business Profile: health check + daily media sync
Schedule::command('google-business-profile:health')->daily()
    ->appendOutputTo(storage_path('logs/schedule.log'));
Schedule::command('google-business-profile:sync --upload --queue')->dailyAt('02:30')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled GBP sync failed'));
Schedule::command('gsc:cleanup-gbp-jpegs --age=24')->dailyAt('03:30')
    ->appendOutputTo(storage_path('logs/schedule.log'));

// Google Business Profile: sync new reviews daily at 06:00 AM CT
Schedule::command('google-business-profile:sync-reviews')->dailyAt('06:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled GBP review sync failed'))
    ->when(fn () => config('services.google.business_profile.enabled'));

// Google Business Profile: match reviews with deep links after sync
Schedule::command('google-business-profile:match-reviews')->dailyAt('06:15')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled GBP review match failed'))
    ->when(fn () => config('services.google.business_profile.enabled'));

// Instagram: 2 posts per day — morning + late afternoon (Central Time)
// Random delay spreads posts naturally within each window
Schedule::command('social:post --platform=instagram --queue --random-delay=150')
    ->dailyAt('07:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled Instagram morning post failed'))
    ->when(fn () => config('services.meta.enabled'));

Schedule::command('social:post --platform=instagram --queue --random-delay=120')
    ->dailyAt('15:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled Instagram afternoon post failed'))
    ->when(fn () => config('services.meta.enabled'));

// Facebook + Google Business Profile: 1 post daily at 10:00 AM CT
Schedule::command('social:post --platform=facebook --queue')->dailyAt('10:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled Facebook post failed'))
    ->when(fn () => config('services.meta.enabled'));

Schedule::command('social:post --platform=google_business --queue')->dailyAt('10:00')
    ->timezone('America/Chicago')
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->onFailure(fn () => logger()->error('Scheduled GBP post failed'))
    ->when(fn () => config('services.meta.enabled'));

// Social Media: weekly health check
Schedule::command('social:health')->weeklyOn(1, '09:00') // Monday 9 AM
    ->appendOutputTo(storage_path('logs/schedule.log'))
    ->when(fn () => config('services.meta.enabled'));

Artisan::command('gsc:cleanup-gbp-jpegs
    {--age=24 : Delete GBP JPGs older than this many hours}
', function () {
    $ageHours = (int) $this->option('age');
    if ($ageHours < 1) {
        $this->error('Age must be at least 1 hour.');
        return 1;
    }

    $cutoff = now()->subHours($ageHours);
    $disk = Storage::disk('public');
    $files = $disk->allFiles('projects');
    $deleted = 0;

    foreach ($files as $file) {
        if (! Str::endsWith($file, '_gbp.jpg')) {
            continue;
        }

        $lastModified = $disk->lastModified($file);
        if ($lastModified === false) {
            continue;
        }

        if ($cutoff->greaterThanOrEqualTo(\Illuminate\Support\Carbon::createFromTimestamp($lastModified))) {
            $disk->delete($file);
            $deleted++;
        }
    }

    $this->info("Deleted {$deleted} GBP JPG files.");
    return 0;
})->purpose('Delete temporary GBP JPG uploads after a retention window');

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
