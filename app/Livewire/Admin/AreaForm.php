<?php

namespace App\Livewire\Admin;

use App\Models\AreaServed;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class AreaForm extends Component
{
    public ?AreaServed $area = null;

    public string $city = '';
    public string $slug = '';
    public ?string $latitude = null;
    public ?string $longitude = null;
    public ?string $intro = null;
    public ?string $local_intro = null;
    public ?string $landmarks = null;
    public ?string $permit_notes = null;

    public string $savedFlash = '';

    public function mount(?AreaServed $area = null): void
    {
        if ($area && $area->exists) {
            $this->area = $area;
            $this->city         = (string) $area->city;
            $this->slug         = (string) $area->slug;
            $this->latitude     = $area->latitude !== null ? (string) $area->latitude : null;
            $this->longitude    = $area->longitude !== null ? (string) $area->longitude : null;
            $this->intro        = $area->intro;
            $this->local_intro  = $area->local_intro;
            $this->landmarks    = $area->landmarks;
            $this->permit_notes = $area->permit_notes;
        } else {
            $this->area = new AreaServed();
        }
    }

    protected function rules(): array
    {
        $id = $this->area?->id;
        return [
            'city'         => ['required', 'string', 'max:120'],
            'slug'         => ['required', 'string', 'max:160', "unique:areas_served,slug,{$id}"],
            'latitude'     => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'    => ['nullable', 'numeric', 'between:-180,180'],
            'intro'        => ['nullable', 'string'],
            'local_intro'  => ['nullable', 'string'],
            'landmarks'    => ['nullable', 'string'],
            'permit_notes' => ['nullable', 'string'],
        ];
    }

    public function updatedCity(string $value): void
    {
        if ($this->area?->exists) {
            return;
        }
        if ($this->slug === '' || $this->slug === Str::slug($this->area->city ?? '')) {
            $this->slug = Str::slug($value);
        }
    }

    public function save()
    {
        $data = $this->validate();

        $isNew = ! ($this->area?->exists);
        $this->area->fill($data);
        $this->area->save();

        // Ensure HasSEO row exists immediately so the admin override panel renders.
        if ($isNew && method_exists($this->area, 'addSEO')) {
            $this->area->refresh();
            if (! $this->area->seo) {
                $this->area->addSEO();
            }
        }

        $this->savedFlash = $isNew ? 'Area created.' : 'Area updated.';

        if ($isNew) {
            return redirect()->route('admin.areas.edit', $this->area);
        }
    }

    public function render()
    {
        return view('livewire.admin.area-form');
    }
}
