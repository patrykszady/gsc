<?php

namespace App\Jobs;

use App\Models\ProjectCollaborator;
use App\Services\Blog\PartnerSiteFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchCollaboratorSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public ProjectCollaborator $collaborator)
    {
        $this->onQueue('ai-content');
    }

    public function handle(PartnerSiteFetcher $fetcher): void
    {
        $fetcher->fetch($this->collaborator);
    }
}
