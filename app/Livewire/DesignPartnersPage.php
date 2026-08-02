<?php

namespace App\Livewire;

use App\Support\SEO\SEOBuilder;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Design professionals we build for (/design-partners).
 *
 * Sibling to TradesIndexPage: same shape, different audience. Trades is "who
 * does the work"; this is "who designed it". Content lives in
 * config/design-partners.php so a tenant that works with no designers simply
 * declares none and the page 404s rather than rendering another company's
 * partner list.
 */
#[Layout('components.layouts.app')]
class DesignPartnersPage extends Component
{
    /** @var array<int, array<string, mixed>> Discipline groups, each with 0+ named firms. */
    public array $groups = [];

    public string $intro = '';

    public function mount(): void
    {
        $this->groups = (array) config('design-partners.groups', []);

        if (! (bool) config('design-partners.enabled', true) || $this->groups === []) {
            abort(404);
        }

        $this->intro = (string) config('design-partners.intro', '');

        app(SEOBuilder::class)
            ->title('Design Professionals We Work With | ' . config('brand.name'))
            ->description('Interior designers, architects and structural engineers whose work '
                . config('brand.name') . ' builds across Chicagoland. Bring your own design team, or '
                . 'let us introduce you to one.')
            ->canonical(url('/design-partners'))
            ->url(url('/design-partners'))
            ->type('website');
    }

    public function render()
    {
        return view('livewire.design-partners-page');
    }
}
