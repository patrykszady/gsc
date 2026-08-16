<?php

namespace App\Livewire\Admin;

use App\Models\ContactSubmission;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\Tag;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.admin')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public ?int $viewingId = null;

    public function view(int $id): void
    {
        $this->viewingId = $id;
        $this->modal('lead-detail')->show();
    }

    public function convertToReal(int $id): void
    {
        $lead = ContactSubmission::find($id);
        if ($lead && $lead->isSpam()) {
            $lead->markAsReal();
            session()->flash('status', "Lead from {$lead->name} converted, sent to Hive, and similar senders will no longer be flagged.");
        }
        $this->modal('lead-detail')->close();
    }

    public function markSpam(int $id): void
    {
        $lead = ContactSubmission::find($id);
        if ($lead && ! $lead->isSpam()) {
            $lead->markAsSpam();
            session()->flash('status', "Lead from {$lead->name} marked as spam; similar senders will be blocked going forward.");
        }
        $this->modal('lead-detail')->close();
    }

    /**
     * Snapshot of the self-driving SEO machinery for the landing page: is it
     * running, what did it do, what's waiting on a human. Every source is
     * guarded — a missing table degrades one number, never the dashboard.
     *
     * @return array{open:int,applied_week:int,measuring:int,next_outcome:?string,problems:int,advisories:int,urgent:array<int,string>,engine_at:?string,healed:int}
     */
    protected function automationSnapshot(): array
    {
        $out = ['open' => 0, 'applied_week' => 0, 'measuring' => 0, 'next_outcome' => null,
            'problems' => 0, 'advisories' => 0, 'urgent' => [], 'engine_at' => null, 'healed' => 0];

        try {
            $out['open'] = (int) \App\Models\SeoAction::where('status', \App\Models\SeoAction::STATUS_PROPOSED)->count();
            $out['applied_week'] = (int) \App\Models\SeoAction::where('applied_at', '>=', now()->subDays(7))->count();
            $out['measuring'] = (int) \App\Models\SeoAction::whereNotNull('applied_at')->whereNull('measured_at')->count();
            $due = \App\Models\SeoAction::whereNull('measured_at')->whereNotNull('measure_after')->min('measure_after');
            $out['next_outcome'] = $due ? 'in ' . \Illuminate\Support\Carbon::parse($due)->diffForHumans(null, true) : null;
            $out['advisories'] = (int) \App\Models\SeoAction::where('risk', \App\Models\SeoAction::RISK_REVIEW)
                ->where('status', \App\Models\SeoAction::STATUS_PROPOSED)->count();
        } catch (\Throwable $e) {
        }

        try {
            $out['problems'] = (int) \App\Models\GscCoverageState::where(
                fn ($q) => $q->where('verdict', '!=', 'PASS')->orWhereNull('verdict')
            )->count();
        } catch (\Throwable $e) {
        }

        try {
            $engine = \App\Services\Seo\RecommendationEngine::latest();
            if ($engine) {
                $out['engine_at'] = \Illuminate\Support\Carbon::parse($engine['generated_at'])->diffForHumans();
                $out['healed'] = count($engine['healed'] ?? []);
                $out['urgent'] = array_values(array_filter(
                    $engine['action_items'] ?? [],
                    fn ($i) => str_starts_with($i, 'URGENT')
                ));
            }
        } catch (\Throwable $e) {
        }

        return $out;
    }

    /** Draft emails keyed by project id for the review-request card. */
    public array $reviewEmails = [];

    /**
     * Save a homeowner email so the weekly reviews:send-requests run can mail
     * them a Google review link. This card exists because the whole
     * review-request pipeline sat idle: the command was built and scheduled,
     * but 0 of 30 projects had a client_email, so it never sent anything —
     * while the Google profile sat at 20 reviews against competitors
     * advertising hundreds. Google review count is the strongest
     * click-through lever a local contractor has.
     */
    public function saveReviewEmail(int $projectId): void
    {
        $email = trim((string) ($this->reviewEmails[$projectId] ?? ''));

        $this->validate(
            ['reviewEmails.' . $projectId => 'required|email'],
            [],
            ['reviewEmails.' . $projectId => 'email'],
        );

        $project = Project::findOrFail($projectId);
        $project->forceFill(['client_email' => $email])->save();

        unset($this->reviewEmails[$projectId]);
    }

    public function render()
    {
        return view('livewire.admin.dashboard', [
            'automation' => $this->automationSnapshot(),
            'reviewPipeline' => [
                'missing' => Project::published()->whereNull('client_email')
                    ->orderByDesc('completed_at')->get(['id', 'title', 'location', 'completed_at']),
                'ready' => Project::whereNotNull('client_email')->whereNull('review_request_sent_at')->count(),
                'sent' => Project::whereNotNull('review_request_sent_at')->count(),
            ],
            'projectCount' => Project::count(),
            'publishedCount' => Project::published()->count(),
            'imageCount' => ProjectImage::count(),
            'tagCount' => Tag::count(),
            'leadCount' => ContactSubmission::count(),
            'leadsToday' => ContactSubmission::whereDate('created_at', today())->count(),
            'leadsThisWeek' => ContactSubmission::where('created_at', '>=', now()->subWeek())->count(),
            'recentProjects' => Project::with('coverImage')
                ->latest()
                ->take(5)
                ->get(),
            'recentLeads' => ContactSubmission::latest()->take(5)->get(),
            'viewing' => $this->viewingId ? ContactSubmission::find($this->viewingId) : null,
        ]);
    }
}
