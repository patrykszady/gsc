<?php

namespace App\Console\Commands;

use App\Jobs\UploadProjectImageToYelp;
use App\Models\ProjectImage;
use App\Services\YelpBusinessService;
use Illuminate\Console\Command;

class SyncYelpPortfolioMedia extends Command
{
    protected $signature = 'yelp:sync-portfolio-media
        {--project= : Limit to a single project ID}
        {--force : Re-upload images even if already synced}
        {--limit=0 : Cap number of images dispatched (0 = unlimited)}
        {--sync : Run jobs synchronously (default: queue)}';

    protected $description = 'Dispatch upload jobs for project images to their Yelp Portfolio Projects.';

    public function handle(YelpBusinessService $service): int
    {
        if (! $service->isConfigured()) {
            $this->error('Yelp business uploader is not configured. Set Yelp email and password in /admin/platforms.');
            return self::FAILURE;
        }

        $query = ProjectImage::query()
            ->whereHas('project', function ($q) {
                $q->where('is_published', true)
                  ->whereNotNull('yelp_portfolio_url');

                if ($projectId = $this->option('project')) {
                    $q->where('id', $projectId);
                }
            });

        if (! $this->option('force')) {
            $query->whereNull('yelp_uploaded_at');
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $count = 0;
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');

        $query->orderBy('id')->each(function (ProjectImage $image) use (&$count, $force, $sync) {
            if ($sync) {
                UploadProjectImageToYelp::dispatchSync($image->id, $force);
            } else {
                UploadProjectImageToYelp::dispatch($image->id, $force)
                    ->onQueue('media-sync');
            }
            $this->line("  - queued image #{$image->id}");
            $count++;
        });

        $this->info("Dispatched {$count} Yelp upload job(s).");
        return self::SUCCESS;
    }
}
