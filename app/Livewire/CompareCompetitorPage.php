<?php

namespace App\Livewire;

use App\Models\Project;
use App\Models\Testimonial;
use App\Services\SeoService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class CompareCompetitorPage extends Component
{
    public string $slug;

    /** @var array<string, mixed> */
    public array $competitor = [];

    /** @var array<int, array<string, string>> */
    public array $criteria = [];

    public function mount(string $slug): void
    {
        if (! (bool) config('competitors.enabled', true)) {
            abort(404);
        }

        $this->slug = $slug;
        $this->competitor = $this->findCompetitor($slug) ?? abort(404);
        $this->criteria = $this->buildCriteria($this->competitor);

        SeoService::compareCompetitor($this->competitor);
    }

    public function render()
    {
        $projects = Project::query()
            ->published()
            ->with(['images'])
            ->latest('completed_at')
            ->take(6)
            ->get();

        $reviewCount = Testimonial::query()->count();

        return view('livewire.compare-competitor-page', [
            'projects' => $projects,
            'reviewCount' => $reviewCount,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findCompetitor(string $slug): ?array
    {
        foreach ((array) config('competitors.competitors', []) as $row) {
            if (($row['slug'] ?? null) === $slug) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $competitor
     * @return array<int, array<string, string>>
     */
    protected function buildCriteria(array $competitor): array
    {
        $criteria = (array) config('competitors.criteria', []);
        $overrides = (array) ($competitor['them'] ?? []);

        $rows = [];
        foreach ($criteria as $row) {
            $key = (string) ($row['key'] ?? '');
            $rows[] = [
                'label' => (string) ($row['label'] ?? $key),
                'us' => (string) ($row['us'] ?? ''),
                'them' => (string) ($overrides[$key] ?? 'Varies — verify directly with the company.'),
            ];
        }

        return $rows;
    }
}
