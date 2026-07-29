<?php

namespace App\Livewire\Admin;

use App\Models\AreaServed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class AreaList extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public ?string $mapFlash = null;

    public function delete(int $id): void
    {
        AreaServed::whereKey($id)->delete();
        $this->dispatch('area-deleted');
        $this->dispatch('areas-map-updated', areas: $this->mapAreas());
    }

    /**
     * Create an area from a map click. City/state come from the client-side
     * reverse geocode; coordinates from the click itself.
     */
    public function createFromMap(string $city, float $lat, float $lng): void
    {
        $city = trim($city);
        if ($city === '' || $lat === 0.0 || $lng === 0.0) {
            $this->mapFlash = 'Could not resolve a town at that point — try clicking closer to its center.';

            return;
        }

        $slug = \Illuminate\Support\Str::slug($city);
        $existing = AreaServed::where('slug', $slug)
            ->orWhereRaw('LOWER(city) = ?', [mb_strtolower($city)])
            ->first();
        if ($existing) {
            $this->mapFlash = "{$existing->city} is already a service area.";
            $this->dispatch('areas-map-updated', areas: $this->mapAreas());

            return;
        }

        $area = AreaServed::create([
            'city' => $city,
            'slug' => $slug,
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        $this->mapFlash = "Added {$area->city}. Give it intro content so the page earns an index slot (it starts empty).";
        $this->dispatch('areas-map-updated', areas: $this->mapAreas());
    }

    /** @return array<int, array{id:int,city:string,slug:string,lat:float,lng:float,hasContent:bool,editUrl:string,publicUrl:string}> */
    public function mapAreas(): array
    {
        return AreaServed::query()
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->orderBy('city')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'city' => (string) $a->city,
                'slug' => (string) $a->slug,
                'lat' => (float) $a->latitude,
                'lng' => (float) $a->longitude,
                'hasContent' => $a->hasUniqueContent(),
                'editUrl' => route('admin.areas.edit', $a),
                'publicUrl' => url('/areas-served/' . $a->slug),
            ])
            ->all();
    }

    public function render()
    {
        $areas = AreaServed::query()
            ->when($this->search !== '', function ($q) {
                $term = "%{$this->search}%";
                $q->where('city', 'like', $term)->orWhere('slug', 'like', $term);
            })
            ->orderBy('city')
            ->paginate(25);

        return view('livewire.admin.area-list', [
            'areas' => $areas,
        ]);
    }
}
