<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Testimonial;
use Illuminate\Console\Command;

class SeoBackfill extends Command
{
    protected $signature = 'seo:backfill';

    protected $description = 'Create empty SEO rows for any HasSEO model missing one (so the admin override panel can edit them).';

    public function handle(): int
    {
        $models = [
            Project::class,
            AreaServed::class,
            Testimonial::class,
        ];

        foreach ($models as $class) {
            $missing = $class::doesntHave('seo')->get();
            $count = $missing->count();
            if ($count === 0) {
                $this->line("{$class}: nothing to backfill.");
                continue;
            }
            $missing->each->addSEO();
            $this->info("{$class}: created {$count} SEO row(s).");
        }

        return self::SUCCESS;
    }
}
