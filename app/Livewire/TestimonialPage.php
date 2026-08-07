<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\ProjectImage;
use App\Models\Testimonial;
use App\Services\SeoService;
use App\Support\SeededRandom;
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

        // Seeded on the review id, not inRandomOrder(): only 9 of the 71
        // reviews link a project, so for the other 62 this fallback IS the hero
        // — and a fresh RAND() per request meant the same review showed a
        // different photo on every reload, and a different one again from the
        // avatar and the /reviews card. Seeded, each review keeps one photo.
        $seed = (int) $this->testimonial->getKey();

        $projectType = $this->normalizeProjectType($this->testimonial->project_type);
        if ($projectType) {
            $cached = SeededRandom::order(
                ProjectImage::query()->whereHas('project', fn ($q) => $q->published()->ofType($projectType)),
                $seed,
            )->first();
        }

        // Final fallback: any image from any published project, same seed.
        if (! $cached) {
            $cached = SeededRandom::order(
                ProjectImage::query()->whereHas('project', fn ($q) => $q->published()),
                $seed,
            )->first();
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
            $cached = SeededRandom::order(
                ProjectImage::query()
                    ->whereIn('project_id', $linkedProjectIds)
                    ->when($heroId, fn ($q) => $q->where('id', '!=', $heroId)),
                (int) $this->testimonial->getKey() + 7919,
            )->first()
                ?: $this->heroImage();

            if ($cached) {
                return $cached;
            }
        }

        // Seeded like the hero (offset so it lands on a different photo), so
        // the avatar is stable per review rather than reshuffling each load.
        $projectType = $this->normalizeProjectType($this->testimonial->project_type);
        if ($projectType) {
            $cached = SeededRandom::order(
                ProjectImage::query()
                    ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
                    ->when($heroId, fn ($q) => $q->where('id', '!=', $heroId)),
                (int) $this->testimonial->getKey() + 7919,
            )->first();
        }

        return $cached ?: null;
    }

    protected function getImageUrl(): string
    {
        $image = $this->avatarImage();
        if ($image) {
            $url = $this->resolveImageUrl($image, 'medium');
            if ($url) {
                return $url;
            }
        }

        return $this->fallbackProjectImageUrl();
    }

    protected function fallbackProjectImageUrl(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $fallbackImage = ProjectImage::query()
            ->whereHas('project', fn ($q) => $q->published())
            ->inRandomOrder()
            ->first();

        $cached = $fallbackImage
            ? $this->resolveImageUrl($fallbackImage, 'medium')
            : asset('images/greg-patryk-thumb.jpg');

        return $cached;
    }

    protected function getThumbnailUrl(): ?string
    {
        $image = $this->heroImage();
        return $image
            ? $this->resolveImageUrl($image, 'large')
            : null;
    }

    protected function getThumbnailThumbUrl(): ?string
    {
        $image = $this->heroImage();
        return $image
            ? $this->resolveImageUrl($image, 'thumb')
            : null;
    }

    protected function resolveImageUrl(ProjectImage $image, string $preferredSize = 'medium'): ?string
    {
        return $image->getWebpThumbnailUrl($preferredSize)
            ?? $image->getThumbnailUrl($preferredSize)
            ?? $image->getWebpThumbnailUrl('large')
            ?? $image->getThumbnailUrl('large')
            ?? $image->url;
    }

    protected function getAreaSlug(): ?string
    {
        $cityName = preg_replace('/,\s*[A-Z]{2}$/', '', $this->testimonial->project_location);
        return AreaServed::where('city', $cityName)->value('slug');
    }

    /**
     * The review body, split into paragraphs.
     *
     * This used to collapse `\s+` to a single space, which flattened the blank
     * lines the reviewer actually typed — 8 of the 71 reviews are written in
     * paragraphs and every one of them rendered as a single wall of text. Runs
     * of horizontal whitespace still collapse; blank lines now survive as the
     * paragraph breaks they were.
     *
     * No breaks are invented for the reviews that genuinely have none: those
     * are handled by measure and leading in the template, not by guessing where
     * someone meant to pause.
     *
     * @return array<int, string>
     */
    protected function getReviewParagraphs(): array
    {
        $text = (string) $this->testimonial->review_description;

        // Remove pasted source URLs that can make the review block look noisy.
        $text = preg_replace('/https?:\/\/\S+/i', '', $text) ?? $text;

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Horizontal whitespace only — \s would take the newlines with it.
        $text = preg_replace('/[^\S\n]+/', ' ', $text) ?? $text;

        $paragraphs = preg_split('/\n\s*\n+/', trim($text)) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $p): string => trim(preg_replace('/\n+/', ' ', $p) ?? $p),
            $paragraphs,
        ), static fn (string $p): bool => $p !== ''));
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
            'home', 'homes' => 'home-remodel',
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
            'reviewParagraphs' => $this->getReviewParagraphs(),
            'areaSlug' => $this->getAreaSlug(),
            'faqs' => $this->getFaqs(),
        ]);
    }
}
