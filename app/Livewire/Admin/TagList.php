<?php

namespace App\Livewire\Admin;

use App\Models\Tag;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.admin')]
#[Title('Tags')]
class TagList extends Component
{
    #[Validate('required|min:2')]
    public string $name = '';

    #[Validate('required')]
    public string $type = 'general';

    public ?int $editingId = null;

    public function create(): void
    {
        $this->validate();

        Tag::create([
            'name' => $this->name,
            'type' => $this->type,
        ]);

        $this->reset(['name', 'type']);
        session()->flash('success', 'Tag created successfully.');
    }

    public function edit(Tag $tag): void
    {
        $this->editingId = $tag->id;
        $this->name = $tag->name;
        $this->type = $tag->type;
    }

    public function update(): void
    {
        $this->validate();

        $tag = Tag::find($this->editingId);
        if ($tag) {
            $tag->update([
                'name' => $this->name,
                'type' => $this->type,
            ]);
        }

        $this->cancelEdit();
        session()->flash('success', 'Tag updated successfully.');
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->reset(['name', 'type']);
    }

    public function delete(Tag $tag): void
    {
        $tag->delete();
        session()->flash('success', 'Tag deleted successfully.');
    }

    public function render()
    {
        return view('livewire.admin.tag-list', [
            'tags' => Tag::withCount('images')->orderBy('type')->orderBy('name')->get(),
            'tagTypes' => Tag::tagTypes(),
        ]);
    }
}
