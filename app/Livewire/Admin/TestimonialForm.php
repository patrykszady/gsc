<?php

namespace App\Livewire\Admin;

use App\Models\Testimonial;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.admin')]
#[Title('Review')]
class TestimonialForm extends Component
{
    public ?Testimonial $testimonial = null;

    #[Validate('required|min:2')]
    public string $reviewer_name = '';

    #[Validate('nullable|string')]
    public string $project_location = '';

    #[Validate('nullable|string')]
    public string $project_type = '';

    #[Validate('required|min:10')]
    public string $review_description = '';

    #[Validate('nullable|date')]
    public ?string $review_date = null;

    #[Validate('nullable|url')]
    public ?string $review_url = null;

    #[Validate('nullable|url')]
    public ?string $review_image = null;

    public function mount(?Testimonial $testimonial = null): void
    {
        if ($testimonial && $testimonial->exists) {
            $this->testimonial = $testimonial;
            $this->reviewer_name = $testimonial->reviewer_name;
            $this->project_location = $testimonial->project_location ?? '';
            $this->project_type = $testimonial->project_type ?? '';
            $this->review_description = $testimonial->review_description;
            $this->review_date = $testimonial->review_date?->format('Y-m-d');
            $this->review_url = $testimonial->review_url;
            $this->review_image = $testimonial->review_image;
        }
    }

    public function save(): void
    {
        $this->review_date = $this->review_date ?: null;
        $this->review_url = $this->review_url ?: null;
        $this->review_image = $this->review_image ?: null;

        $this->validate();

        $data = [
            'reviewer_name' => $this->reviewer_name,
            'project_location' => $this->project_location ?: null,
            'project_type' => $this->project_type ?: null,
            'review_description' => $this->review_description,
            'review_date' => $this->review_date ? Carbon::parse($this->review_date) : null,
            'review_url' => $this->review_url,
            'review_image' => $this->review_image,
        ];

        if ($this->testimonial?->exists) {
            $this->testimonial->update($data);
        } else {
            $this->testimonial = Testimonial::create($data);
        }

        session()->flash('success', $this->testimonial->wasRecentlyCreated ? 'Review created successfully.' : 'Review updated successfully.');

        $this->redirect(route('admin.testimonials.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.testimonial-form');
    }
}
