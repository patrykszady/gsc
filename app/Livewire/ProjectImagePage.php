<?php

namespace App\Livewire;

use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\GoogleBusinessProfileService;
use App\Support\SEO\SEOBuilder;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ProjectImagePage extends Component
{
    public Project $project;
    public ProjectImage $image;
    public ?int $previousImageId = null;
    public ?int $nextImageId = null;
    public int $currentPosition = 1;
    public int $totalImages = 0;

    public function mount(Project $project, ProjectImage $image): void
    {
        // Only show published projects
        if (!$project->is_published) {
            abort(404);
        }

        // Wrong project in the URL (stale link, or a historical slug collision
        // resolving to another project's image): 301 to the image's canonical
        // home instead of 404ing a page Google may have indexed.
        if ($image->project_id !== $project->id) {
            $ownerSlug = $image->project?->slug;
            if ($ownerSlug) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    redirect()->to(route('projects.image', ['project' => $ownerSlug, 'image' => $image->slug ?: $image->id]), 301)
                );
            }
            abort(404);
        }

        $this->project = $project;
        $this->image = $image;
        $this->loadImageNavigation();
        
        // Set SEO meta
        $this->setSeoMeta();
    }
    
    /**
     * Return text as-is (kept for compatibility with view usage).
     */
    public function localizeText(?string $text): ?string
    {
        return $text;
    }
    
    /**
     * Get the canonical URL (without area for area variations).
     */
    public function getCanonicalUrl(): string
    {
        $imageKey = $this->image->slug ?: $this->image->id;

        return route('projects.image', ['project' => $this->project, 'image' => $imageKey]);
    }
    
    protected function loadImageNavigation(): void
    {
        // Load all images to determine position and navigation
        $images = $this->project->images()->orderBy('sort_order')->get();
        $this->totalImages = $images->count();

        $currentIndex = $images->search(fn($img) => $img->id === $this->image->id);
        $this->currentPosition = $currentIndex !== false ? $currentIndex + 1 : 1;

        if ($currentIndex !== false && $this->totalImages > 1) {
            // Wrap around: previous of first = last, next of last = first
            $prevIndex = ($currentIndex - 1 + $this->totalImages) % $this->totalImages;
            $nextIndex = ($currentIndex + 1) % $this->totalImages;
            
            $this->previousImageId = $images[$prevIndex]->id;
            $this->nextImageId = $images[$nextIndex]->id;
        }
    }
    
    public function goToImage(int $imageId): void
    {
        $newImage = ProjectImage::find($imageId);
        
        if (!$newImage || $newImage->project_id !== $this->project->id) {
            return;
        }
        
        $this->image = $newImage;
        $this->loadImageNavigation();
        
        // Update the URL without full page navigation
        $imageKey = $this->image->slug ?: $this->image->id;

        $this->dispatch('urlChanged', url: route('projects.image', ['project' => $this->project, 'image' => $imageKey]));
    }
    
    public function nextImage(): void
    {
        if ($this->nextImageId && $this->totalImages > 1) {
            $this->goToImage($this->nextImageId);
        }
    }
    
    public function previousImage(): void
    {
        if ($this->previousImageId && $this->totalImages > 1) {
            $this->goToImage($this->previousImageId);
        }
    }

    protected function setSeoMeta(): void
    {
        // Get location-aware text
        $location = $this->project->location;
        
        // These fields feed SEOBuilder directly (not SeoService::setTags), so
        // clamp here: alt-text titles ran to 125+ chars and captions to 286 —
        // both far past what Google displays, and both got rewritten in SERPs.
        $title = \Illuminate\Support\Str::limit(
            $this->localizeText($this->image->seo_alt_text)
                ?: "{$this->project->title} - Photo {$this->currentPosition}",
            60,
            ''
        );

        $description = $this->localizeText($this->image->seo_caption)
            ?: "View photo {$this->currentPosition} of {$this->totalImages} from {$this->project->title}. "
               . ($location ? "Located in {$location}. " : '')
               . "Professional remodeling by GS Construction.";
        $description = \Illuminate\Support\Str::limit(trim($description), 160, '');
        if (($cut = mb_strrpos($description, '.')) !== false && $cut > 96) {
            $description = mb_substr($description, 0, $cut + 1);
        }

        $imageUrl = $this->image->getAnyUrl('large');
        $googleUrl = $this->image->google_places_media_url;
        if (! $googleUrl && $this->image->google_places_media_name) {
            $googleUrl = app(GoogleBusinessProfileService::class)
                ->getMediaUrlCached($this->image->google_places_media_name);
        }

        if (!is_string($imageUrl) || trim($imageUrl) === '') {
            $imageUrl = is_string($googleUrl) && trim($googleUrl) !== ''
                ? $googleUrl
                : asset('images/greg-patryk.jpg');
        }
        
        // Canonical always points to the base URL (no area)
        $canonicalUrl = $this->getCanonicalUrl();
        $currentUrl = $canonicalUrl;

        app(SEOBuilder::class)
            ->title($title)
            ->description($description)
            ->canonical($canonicalUrl)
            ->url($currentUrl)
            ->type('article')
            // Always OUR hosted image — pointing og:image at the Google-hosted
            // copy (lh3.googleusercontent.com) tells crawlers the page's image
            // lives off-domain and starves our own image indexing. The Google
            // copy stays discoverable via the schema sameAs below.
            ->image($imageUrl);

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'ImageObject',
            'name' => $title,
            'description' => $description,
            'url' => $currentUrl,
            'contentUrl' => $imageUrl,
            'thumbnail' => $this->image->getThumbnailUrl('thumb'),
            'representativeOfPage' => (bool) $this->image->is_cover,
            'caption' => $this->image->seo_caption,
        ];
        if ($googleUrl) {
            $schema['sameAs'] = $googleUrl;
        }
        if ($this->image->width && $this->image->height) {
            $schema['width'] = $this->image->width;
            $schema['height'] = $this->image->height;
        }
        $this->imageSchema = $schema;
    }

    /**
     * JSON-LD ImageObject schema for this image (rendered by the view).
     *
     * @var array<string, mixed>
     */
    public array $imageSchema = [];

    protected function getProjectTypeLabel(): string
    {
        $types = Project::projectTypes();
        return $types[$this->project->project_type] ?? ucfirst(str_replace('-', ' ', $this->project->project_type));
    }

    public function render()
    {
        return view('livewire.project-image-page', [
            'projectTypeLabel' => $this->getProjectTypeLabel(),
        ]);
    }
}
