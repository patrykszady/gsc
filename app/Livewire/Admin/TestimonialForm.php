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

    public array $review_urls = [['platform' => '', 'url' => '']];

    public function mount(?Testimonial $testimonial = null): void
    {
        if ($testimonial && $testimonial->exists) {
            $this->testimonial = $testimonial;
            $this->reviewer_name = $testimonial->reviewer_name;
            $this->project_location = $testimonial->project_location ?? '';
            $this->project_type = $testimonial->project_type ?? '';
            $this->review_description = $testimonial->review_description;
            $this->review_date = $testimonial->review_date?->format('Y-m-d');

            $urls = $testimonial->reviewUrls->map(fn ($u) => ['platform' => $u->platform, 'url' => $u->url])->toArray();
            $this->review_urls = count($urls) ? $urls : [['platform' => '', 'url' => '']];
        }
    }

    public function addUrl(): void
    {
        $this->review_urls[] = ['platform' => '', 'url' => ''];
    }

    public function removeUrl(int $index): void
    {
        unset($this->review_urls[$index]);
        $this->review_urls = array_values($this->review_urls);

        if (empty($this->review_urls)) {
            $this->review_urls = [['platform' => '', 'url' => '']];
        }
    }

    public function save(): void
    {
        $this->review_date = $this->review_date ?: null;

        $this->validate([
            'reviewer_name' => 'required|min:2',
            'review_description' => 'required|min:10',
            'project_location' => 'nullable|string',
            'project_type' => 'nullable|string',
            'review_date' => 'nullable|date',
            'review_urls.*.platform' => 'nullable|string|max:50',
            'review_urls.*.url' => 'nullable|url',
        ]);

        $data = [
            'reviewer_name' => $this->reviewer_name,
            'project_location' => $this->project_location ?: null,
            'project_type' => $this->project_type ?: null,
            'review_description' => $this->review_description,
            'review_date' => $this->review_date ? Carbon::parse($this->review_date) : null,
        ];

        if ($this->testimonial?->exists) {
            $this->testimonial->update($data);
        } else {
            $this->testimonial = Testimonial::create($data);
        }

        // Sync review URLs
        $this->testimonial->reviewUrls()->delete();
        foreach ($this->review_urls as $entry) {
            if (! empty($entry['url']) && ! empty($entry['platform'])) {
                $this->testimonial->reviewUrls()->create([
                    'platform' => $entry['platform'],
                    'url' => $entry['url'],
                ]);
            }
        }

        session()->flash('success', $this->testimonial->wasRecentlyCreated ? 'Review created successfully.' : 'Review updated successfully.');

        $this->redirect(route('admin.testimonials.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.testimonial-form');
    }
}
