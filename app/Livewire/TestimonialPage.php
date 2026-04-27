<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\ProjectImage;
use App\Models\Testimonial;
use App\Services\SeoService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class TestimonialPage extends Component
{
    public Testimonial $testimonial;

    public function mount(Testimonial $testimonial): void
    {
        $this->testimonial = $testimonial->loadMissing('reviewUrls', 'projects');

        SeoService::testimonial($testimonial);
    }

    /**
     * Pick the hero image: a cover image from a linked project if available,
     * otherwise a random image from any published project of the same type.
     */
    protected function heroImage(): ?ProjectImage
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached ?: null;
        }

        $linkedProjectIds = $this->testimonial->projects->pluck('id');

        if ($linkedProjectIds->isNotEmpty()) {
            $cached = ProjectImage::query()
                ->whereIn('project_id', $linkedProjectIds)
                ->where('is_cover', true)
                ->first()
                ?: ProjectImage::query()
                    ->whereIn('project_id', $linkedProjectIds)
                    ->inRandomOrder()
                    ->first();

            if ($cached) {
                return $cached;
            }
        }

        $projectType = $this->normalizeProjectType($this->testimonial->project_type);
        if ($projectType) {
            $cached = ProjectImage::query()
                ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
                ->inRandomOrder()
                ->first();
        }

        return $cached ?: null;
    }

    /**
     * Pick the small avatar image: prefer a different image from a linked project
     * (so it doesn't visually duplicate the hero); otherwise a random of-type image.
     */
    protected function avatarImage(): ?ProjectImage
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached ?: null;
        }

        $linkedProjectIds = $this->testimonial->projects->pluck('id');
        $heroId = $this->heroImage()?->id;

        if ($linkedProjectIds->isNotEmpty()) {
            $cached = ProjectImage::query()
                ->whereIn('project_id', $linkedProjectIds)
                ->when($heroId, fn ($q) => $q->where('id', '!=', $heroId))
                ->inRandomOrder()
                ->first()
                ?: $this->heroImage();

            if ($cached) {
                return $cached;
            }
        }

        $projectType = $this->normalizeProjectType($this->testimonial->project_type);
        if ($projectType) {
            $cached = ProjectImage::query()
                ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
                ->when($heroId, fn ($q) => $q->where('id', '!=', $heroId))
                ->inRandomOrder()
                ->first();
        }

        return $cached ?: null;
    }

    protected function getImageUrl(): string
    {
        $image = $this->avatarImage();
        if ($image) {
            return $image->getThumbnailUrl('medium');
        }

        return 'https://ui-avatars.com/api/?name=' . urlencode($this->testimonial->display_name) . '&background=0ea5e9&color=fff&size=256';
    }

    protected function getThumbnailUrl(): ?string
    {
        $image = $this->heroImage();
        return $image
            ? ($image->getWebpThumbnailUrl('large') ?? $image->getThumbnailUrl('large') ?? $image->url)
            : null;
    }

    protected function getThumbnailThumbUrl(): ?string
    {
        $image = $this->heroImage();
        return $image
            ? ($image->getWebpThumbnailUrl('thumb') ?? $image->getThumbnailUrl('thumb'))
            : null;
    }

    protected function getAreaSlug(): ?string
    {
        $cityName = preg_replace('/,\s*[A-Z]{2}$/', '', $this->testimonial->project_location);
        return AreaServed::where('city', $cityName)->value('slug');
    }

    protected function getReviewText(): string
    {
        $text = trim((string) $this->testimonial->review_description);

        // Remove pasted source URLs that can make the review block look noisy.
        $text = preg_replace('/https?:\/\/\S+/i', '', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    protected function normalizeProjectType(?string $testimonialProjectType): ?string
    {
        if (! $testimonialProjectType) {
            return null;
        }

        $type = strtolower(trim($testimonialProjectType));

        return match ($type) {
            'kitchens', 'kitchen' => 'kitchen',
            'bathrooms', 'bathroom' => 'bathroom',
            'basements', 'basement' => 'basement',
            'home-remodel', 'home-remodels', 'home remodel', 'home remodels', 'whole-home', 'whole home' => 'home-remodel',
            'additions', 'addition' => 'addition',
            'mudroom', 'mudrooms', 'laundry', 'laundry room', 'laundry rooms', 'mudroom/laundry', 'mudroom / laundry' => 'mudroom',
            'exteriors', 'exterior' => 'exterior',
            default => null,
        };
    }

    protected function getFaqs(): array
    {
        $projectType = ucfirst($this->testimonial->project_type ?? 'home remodel');
        $location = $this->testimonial->project_location ?: 'the Chicagoland area';

        return [
            [
                'question' => "Is this {$projectType} review from {$location} a real customer review?",
                'answer' => 'Yes. This review is from a real GS Construction customer and is published from a verified review source.',
            ],
            [
                'question' => 'Can I see photos from this homeowner project?',
                'answer' => 'Yes. The images shown on this page are pulled from the linked project so you can see visuals from the same homeowner project as the review.',
            ],
            [
                'question' => 'Can I read the original review on Google or Yelp?',
                'answer' => 'Yes. If a source link is available, use the platform button under the reviewer details to open the original review.',
            ],
            [
                'question' => 'How can I get a quote for a similar remodel?',
                'answer' => 'Call (224) 735-4200 or contact us through the website to schedule a free in-home consultation and receive a detailed estimate.',
            ],
        ];
    }

    public function render()
    {
        return view('livewire.testimonial-page', [
            'imageUrl' => $this->getImageUrl(),
            'thumbnailUrl' => $this->getThumbnailUrl(),
            'thumbnailThumbUrl' => $this->getThumbnailThumbUrl(),
            'reviewText' => $this->getReviewText(),
            'areaSlug' => $this->getAreaSlug(),
            'faqs' => $this->getFaqs(),
        ]);
    }
}
