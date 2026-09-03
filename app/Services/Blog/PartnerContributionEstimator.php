<?php

namespace App\Services\Blog;

use App\Models\Project;
use App\Models\ProjectCollaborator;
use App\Services\AiContentService;
use Illuminate\Support\Str;

/**
 * When a partner is credited without a note, work out what they most likely
 * provided on THIS project: match the services their website describes
 * against the project's type and description, and write one plain sentence
 * in our voice. Stored as inferred_note; an admin-entered note always wins.
 */
class PartnerContributionEstimator
{
    public function __construct(protected AiContentService $ai) {}

    public function estimate(ProjectCollaborator $collaborator, ?Project $project = null): ?string
    {
        $project ??= $collaborator->project;
        if (! $project) {
            return null;
        }

        $typeLabel = Project::projectTypes()[$project->project_type] ?? Str::headline((string) $project->project_type);
        $where = $project->location ? ", {$project->location}" : '';
        $about = trim(implode("\n", array_filter([
            $collaborator->site_title ? "Site title: {$collaborator->site_title}" : null,
            $collaborator->site_description ? "Site description: {$collaborator->site_description}" : null,
            $collaborator->site_excerpt ? "Site text: " . Str::limit((string) $collaborator->site_excerpt, 3000, '') : null,
        ])));

        $prompt = <<<PROMPT
GS Construction is a remodeling contractor. A partner worked with us on a project. Estimate what the
partner most likely provided on THIS project by matching the services their website describes to the
project. Be conservative: only services their site actually offers and that a {$typeLabel} would need.
GS Construction itself always did the consultation, estimate, permits, scheduling and construction — never
credit the partner with those.

PARTNER: {$collaborator->name} — role: {$collaborator->roleLabel()}
{$about}

PROJECT: {$project->title} ({$typeLabel}{$where})
Description: {$project->description}

Return ONLY JSON: {"services": ["short service names their site offers that apply here"],
"contribution": "one sentence, past tense, in first person plural from GS Construction's side, saying
what they handled on this project — e.g. 'They handled the interior design, the layout and the cabinetry
selections while we handled the build.' No hedging words like 'likely' in the sentence."}
PROMPT;
        $raw = $this->ai->generateText($prompt, 400, 0.3);
        if ($raw === null) {
            return null;
        }
        $raw = preg_replace('/^```json\s*|^```\s*|\s*```$/im', '', trim($raw));
        $data = json_decode(trim((string) $raw), true);
        $sentence = is_array($data) ? trim((string) ($data['contribution'] ?? '')) : '';
        if ($sentence === '') {
            return null;
        }

        $collaborator->forceFill(['inferred_note' => Str::limit($sentence, 500, ''), 'inferred_at' => now()])->save();

        return $collaborator->inferred_note;
    }
}
