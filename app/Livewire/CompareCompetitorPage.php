<?php

namespace App\Livewire;

use App\Models\Project;
use App\Models\Testimonial;
use App\Services\SeoService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.app')]
class CompareCompetitorPage extends Component
{
    #[Locked]
    public string $slug = '';

    /**
     * Untyped on purpose. Livewire's page-mount pipeline can attempt to assign
     * matching public props from request input *before* mount() runs (e.g.
     * crafted query strings or stray snapshot keys from bot/crawler traffic).
     * Strict `array` typing here caused production TypeErrors. mount() always
     * sets a valid array; the #[Locked] attribute prevents client-side updates.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public $competitor = [];

    /** @var array<int, array<string, string>> */
    #[Locked]
    public $criteria = [];

    public function mount(string $slug): void
    {
        if (! (bool) config('competitors.enabled', true)) {
            abort(404);
        }

        $this->slug = $slug;
        $found = $this->findCompetitor($slug);
        if (! is_array($found)) {
            abort(404);
        }
        $this->competitor = $found;
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
