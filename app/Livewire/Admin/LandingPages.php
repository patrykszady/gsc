<?php

namespace App\Livewire\Admin;

use App\Models\LandingPage;
use App\Services\Seo\LandingPageContentGenerator;
use App\Services\Seo\TitleMetaGenerator;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin management for demand-driven landing pages: generate a new one from
 * real data, preview drafts, publish/unpublish, and delete. Autopilot-created
 * drafts land here for review before they go live.
 */
#[Layout('components.layouts.admin')]
#[Title('Landing Pages')]
class LandingPages extends Component
{
    use WithPagination;

    public string $genService = 'kitchen-remodeling';
    public string $genCity = '';
    public string $genModifier = '';

    public ?string $flash = null;
    public ?string $error = null;

    public function generate(): void
    {
        $this->error = $this->flash = null;

        $city = trim($this->genCity);
        if ($city === '') {
            $this->error = 'Enter a city.';

            return;
        }

        $content = (new LandingPageContentGenerator())->build(
            $this->genService,
            $city,
            $this->genModifier ?: null,
        );

        if ($content === null) {
            $this->error = "No matching project proof for {$this->genService} — can't build a non-thin page. Add a relevant project first.";

            return;
        }

        if (LandingPage::where('slug', $content['slug'])->exists()) {
            $this->error = "A page already exists at /remodeling/{$content['slug']}.";

            return;
        }

        $page = LandingPage::create(array_merge($content, [
            'source' => 'manual',
            'status' => LandingPage::STATUS_DRAFT,
        ]));

        $this->genCity = $this->genModifier = '';
        $this->flash = "Draft created: {$page->h1}. Preview it, then publish.";
    }

    public function publish(int $id): void
    {
        $page = LandingPage::find($id);
        if (! $page) {
            return;
        }
        if (! $page->hasProof()) {
            $this->error = 'Cannot publish a page with no project proof.';

            return;
        }
        $page->update(['status' => LandingPage::STATUS_PUBLISHED, 'published_at' => now()]);
        $this->flash = "Published /remodeling/{$page->slug}. Run sitemap:generate to submit it.";
        $this->regenerateSitemapQuietly();
    }

    public function unpublish(int $id): void
    {
        LandingPage::whereKey($id)->update(['status' => LandingPage::STATUS_DRAFT, 'published_at' => null]);
        $this->flash = 'Page unpublished (back to draft).';
        $this->regenerateSitemapQuietly();
    }

    public function delete(int $id): void
    {
        LandingPage::whereKey($id)->delete();
        $this->flash = 'Page deleted.';
    }

    private function regenerateSitemapQuietly(): void
    {
        try {
            Artisan::call('sitemap:generate');
        } catch (\Throwable) {
            // Sitemap can be regenerated manually; don't block the UI.
        }
    }

    public function render()
    {
        return view('livewire.admin.landing-pages', [
            'pages' => LandingPage::orderByDesc('created_at')->paginate(20),
            'services' => TitleMetaGenerator::SERVICES,
        ]);
    }
}
