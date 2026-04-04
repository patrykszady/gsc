<?php

namespace App\Livewire\Admin;

use App\Models\Project;
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

    public array $project_ids = [];

    public array $review_urls = [['platform' => '', 'url' => '']];

    private function inferPlatformFromUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./', '', $host));

        if (str_contains($host, 'google.') || $host === 'g.page') {
            return 'google';
        }

        if (str_contains($host, 'yelp.')) {
            return 'yelp';
        }

        if (str_contains($host, 'facebook.') || $host === 'fb.com') {
            return 'facebook';
        }

        if (str_contains($host, 'houzz.')) {
            return 'houzz';
        }

        if (str_contains($host, 'angi.') || str_contains($host, 'angieslist.')) {
            return 'angi';
        }

        return 'other';
    }

    public function mount(?Testimonial $testimonial = null): void
    {
        if ($testimonial && $testimonial->exists) {
            $this->testimonial = $testimonial;
            $this->reviewer_name = $testimonial->reviewer_name;
            $this->project_location = $testimonial->project_location ?? '';
            $this->project_type = $testimonial->project_type ?? '';
            $this->review_description = $testimonial->review_description;
            $this->review_date = $testimonial->review_date?->format('Y-m-d');
            $this->project_ids = $testimonial->projects->pluck('id')->map(fn ($id) => (string) $id)->toArray();

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

    public function updatedReviewUrls($value, $key): void
    {
        if (! str_ends_with($key, '.url') || ! is_string($value)) {
            return;
        }

        $parsed = parse_url($value);
        if (empty($parsed['query'])) {
            return;
        }

        parse_str($parsed['query'], $params);
        $cleaned = array_filter($params, fn ($k) => ! str_starts_with($k, 'utm_'), ARRAY_FILTER_USE_KEY);

        $clean = $parsed['scheme'] . '://' . $parsed['host'] . ($parsed['path'] ?? '');
        if ($cleaned) {
            $clean .= '?' . http_build_query($cleaned);
        }

        // e.g. key = "0.url" → index 0
        $index = (int) explode('.', $key)[0];
        $this->review_urls[$index]['url'] = $clean;
    }

    public function updatedProjectIds(): void
    {
        $lastId = end($this->project_ids);
        if ($lastId && $project = Project::find($lastId)) {
            $this->project_location = $project->location ?? $this->project_location;
            $this->project_type = $project->project_type ?? $this->project_type;
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
            'project_ids' => 'nullable|array',
            'project_ids.*' => 'exists:projects,id',
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

        // Sync linked projects
        $this->testimonial->projects()->sync(array_filter($this->project_ids));

        // Sync review URLs
        $this->testimonial->reviewUrls()->delete();
        foreach ($this->review_urls as $entry) {
            $url = trim((string) ($entry['url'] ?? ''));
            $manualPlatform = trim((string) ($entry['platform'] ?? ''));

            if ($url === '') {
                continue;
            }

            $platform = $this->inferPlatformFromUrl($url) ?? $manualPlatform;

            if ($platform !== '') {
                $this->testimonial->reviewUrls()->create([
                    'platform' => $platform,
                    'url' => $url,
                ]);
            }
        }

        session()->flash('success', $this->testimonial->wasRecentlyCreated ? 'Review created successfully.' : 'Review updated successfully.');

        $this->redirect(route('admin.testimonials.index'), navigate: true);
    }

    public function getDisplayPreview(): string
    {
        $name = trim($this->reviewer_name);

        if (! $name) {
            return 'First L';
        }

        $parts = preg_split('/\s+/', $name);

        if (count($parts) < 2) {
            return $name;
        }

        return $parts[0] . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1));
    }

    public function render()
    {
        return view('livewire.admin.testimonial-form', [
            'projects' => Project::with('coverImage')->orderByDesc('completed_at')->get(),
        ]);
    }
}
