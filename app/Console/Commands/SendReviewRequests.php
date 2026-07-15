<?php

namespace App\Console\Commands;

use App\Mail\ReviewRequest;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendReviewRequests extends Command
{
    protected $signature = 'reviews:send-requests
        {--dry-run : List who would be emailed without sending}
        {--project= : Send for a single project ID (ignores the recency window)}
        {--max-age-days=90 : Skip projects completed longer ago than this}';

    protected $description = 'Email a Google review request to homeowners of recently completed projects';

    public function handle(): int
    {
        $query = Project::query()
            ->whereNotNull('client_email')
            ->whereNotNull('completed_at')
            ->whereNull('review_request_sent_at');

        if ($id = $this->option('project')) {
            $query->whereKey($id);
        } else {
            $query->where('completed_at', '>=', now()->subDays((int) $this->option('max-age-days')));
        }

        $projects = $query->get();

        if ($projects->isEmpty()) {
            $this->info('No pending review requests.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($projects as $project) {
            $reviewUrl = $project->getReviewRequestUrl();

            if (! $reviewUrl) {
                $this->warn("#{$project->id} {$project->title}: no review URL configured, skipping.");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("[dry-run] #{$project->id} {$project->title} → {$project->client_email} ({$reviewUrl})");

                continue;
            }

            try {
                Mail::to($project->client_email)->send(new ReviewRequest($project, $reviewUrl));
            } catch (\Throwable $e) {
                $this->error("#{$project->id} {$project->title}: send failed — {$e->getMessage()}");
                logger()->error('[ReviewRequest] Send failed', [
                    'project_id' => $project->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $project->forceFill(['review_request_sent_at' => now()])->save();
            $this->info("Sent review request for #{$project->id} {$project->title} to {$project->client_email}.");
            $sent++;
        }

        if (! $this->option('dry-run')) {
            $this->info("Done — {$sent} review request(s) sent.");
        }

        return self::SUCCESS;
    }
}
