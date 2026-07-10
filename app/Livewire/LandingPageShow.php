<?php

namespace App\Livewire;

use App\Models\LandingPage;
use App\Support\SEO\SEOBuilder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Public renderer for a demand-driven landing page (/remodeling/{slug}).
 *
 * Drafts 404 for the public but are previewable by an authenticated admin via
 * ?preview=1. Pages that don't clear the proof gate render noindex so a
 * thin page can never leak into the index.
 */
#[Layout('components.layouts.app')]
class LandingPageShow extends Component
{
    public LandingPage $page;

    public function mount(string $slug): void
    {
        $query = LandingPage::where('slug', $slug);

        // Only published pages are public; admins may preview drafts.
        if (! (Auth::check() && request()->boolean('preview'))) {
            $query->published();
        }

        $this->page = $query->firstOrFail();

        $seo = app(SEOBuilder::class);
        $seo->title($this->page->title)
            ->description($this->page->meta_description)
            ->canonical($this->page->url())
            ->url($this->page->url())
            ->type('website');

        if ($this->page->hero_image) {
            $seo->image($this->page->hero_image);
        }

        // Proof-gated indexing: never index a thin/unpublished page.
        if (! $this->page->shouldIndex()) {
            $seo->markNoindex();
        }
    }

    public function render()
    {
        return view('livewire.landing-page-show', [
            'projects' => $this->page->proofProjects(),
        ]);
    }
}
