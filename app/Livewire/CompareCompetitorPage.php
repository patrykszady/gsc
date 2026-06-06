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
            'faqs' => $this->buildFaqs($this->competitor),
            'lastVerified' => (string) config('competitors.last_verified', ''),
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
            $fallback = (string) ($row['them_default'] ?? 'Varies — verify directly with the company.');
            $rows[] = [
                'label' => (string) ($row['label'] ?? $key),
                'us' => (string) ($row['us'] ?? ''),
                'them' => (string) ($overrides[$key] ?? $fallback),
                'why' => (string) ($row['why'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Build a small set of unique, factual FAQs per competitor. Rendered as a
     * visible FAQ block + FAQPage schema for long-tail "{brand} vs / alternative"
     * intent and rich-result eligibility. Kept neutral and SEO-safe.
     *
     * @param array<string, mixed> $competitor
     * @return array<int, array<string, string>>
     */
    protected function buildFaqs(array $competitor): array
    {
        $name = (string) ($competitor['name'] ?? 'this company');

        $faqs = [
            [
                'question' => "Is GS Construction a good alternative to {$name}?",
                'answer' => "If you want to work directly with the owners and keep control of your design and materials, GS Construction is a strong alternative to {$name}. Greg and Patryk Szady are a father-son team who run every project from the first call to the final walkthrough — there is no rotating cast of coordinators, and you can bring your own designer or architect (or be your own) and shop your own materials from our trusted material sources.",
            ],
            [
                'question' => "How does GS Construction's pricing compare to {$name}?",
                'answer' => "GS Construction gives you an itemized, transparent estimate with no labor marked up through layers of middlemen, so you can see exactly what you are paying for. Pricing on any remodel depends on scope and finishes, so the best way to compare is to request an itemized estimate from GS Construction and from {$name} and put them side by side.",
            ],
            [
                'question' => "What areas does GS Construction serve?",
                'answer' => 'GS Construction serves the northwest Chicago suburbs — including Arlington Heights, Palatine, Schaumburg, Barrington, and surrounding communities — for kitchen, bathroom, and whole-home remodels, additions, basements, exteriors, and mudrooms.',
            ],
            [
                'question' => 'Can I bring my own designer or buy my own materials?',
                'answer' => 'Yes. You can collaborate with the independent designer or architect of your choice, or design the project yourself. We point you to our trusted material sources, follow your requirements, and install the materials you purchase — your design, your decisions.',
            ],
        ];

        return $faqs;
    }
}
