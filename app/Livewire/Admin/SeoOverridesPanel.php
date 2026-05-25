<?php

namespace App\Livewire\Admin;

use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class SeoOverridesPanel extends Component
{
    public Model $model;

    public ?string $title = null;
    public ?string $description = null;
    public ?string $author = null;
    public ?string $image = null;
    public ?string $canonical_url = null;
    public ?string $robots = null;

    public string $savedFlash = '';

    public function mount(Model $model): void
    {
        $this->model = $model;

        if (! method_exists($model, 'seo')) {
            return;
        }

        $seo = $model->seo;
        if ($seo) {
            $this->title         = $seo->title;
            $this->description   = $seo->description;
            $this->author        = $seo->author;
            $this->image         = $seo->image;
            $this->canonical_url = $seo->canonical_url;
            $this->robots        = $seo->robots;
        }
    }

    public function save(): void
    {
        $this->validate([
            'title'         => ['nullable', 'string', 'max:255'],
            'description'   => ['nullable', 'string', 'max:500'],
            'author'        => ['nullable', 'string', 'max:255'],
            'image'         => ['nullable', 'string', 'max:2048'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
            'robots'        => ['nullable', 'string', 'max:255'],
        ]);

        $attrs = [
            'title'         => $this->nullIfBlank($this->title),
            'description'   => $this->nullIfBlank($this->description),
            'author'        => $this->nullIfBlank($this->author),
            'image'         => $this->nullIfBlank($this->image),
            'canonical_url' => $this->nullIfBlank($this->canonical_url),
            'robots'        => $this->nullIfBlank($this->robots),
        ];

        // Polymorphic morphOne; updateOrCreate works on the relation.
        $this->model->seo()->updateOrCreate([], $attrs);

        $this->savedFlash = 'SEO overrides saved.';
        $this->dispatch('seo-overrides-saved');
    }

    public function clearAll(): void
    {
        if ($seo = $this->model->seo) {
            $seo->update([
                'title' => null, 'description' => null, 'author' => null,
                'image' => null, 'canonical_url' => null, 'robots' => null,
            ]);
        }
        $this->title = $this->description = $this->author = null;
        $this->image = $this->canonical_url = $this->robots = null;
        $this->savedFlash = 'All overrides cleared. Dynamic SEO will be used.';
    }

    protected function nullIfBlank(?string $v): ?string
    {
        if ($v === null) return null;
        $t = trim($v);
        return $t === '' ? null : $t;
    }

    public function render()
    {
        return view('livewire.admin.seo-overrides-panel');
    }
}
