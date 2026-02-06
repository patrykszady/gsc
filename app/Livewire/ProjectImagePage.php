<?php

namespace App\Livewire;

use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\GoogleBusinessProfileService;
use Artesaos\SEOTools\Facades\JsonLd;
use Artesaos\SEOTools\Facades\OpenGraph;
use Artesaos\SEOTools\Facades\SEOMeta;
use Artesaos\SEOTools\Facades\TwitterCard;
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

        // Ensure image belongs to project
        if ($image->project_id !== $project->id) {
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
        
        $title = $this->localizeText($this->image->alt_text) 
            ?: "{$this->project->title} - Photo {$this->currentPosition}";
        
        $description = $this->localizeText($this->image->caption) 
            ?: "View photo {$this->currentPosition} of {$this->totalImages} from {$this->project->title}. "
               . ($location ? "Located in {$location}. " : '')
               . "Professional remodeling by GS Construction.";

        $imageUrl = $this->image->getThumbnailUrl('large');
        $googleUrl = null;
        if ($this->image->google_places_media_name) {
            $googleUrl = app(GoogleBusinessProfileService::class)
                ->getMediaUrlCached($this->image->google_places_media_name);
        }
        
        // Canonical always points to the base URL (no area)
        $canonicalUrl = $this->getCanonicalUrl();
        $currentUrl = $canonicalUrl;

        SEOMeta::setTitle($title);
        SEOMeta::setDescription($description);
        SEOMeta::setCanonical($canonicalUrl);

        OpenGraph::setTitle($title);
        OpenGraph::setDescription($description);
        OpenGraph::setUrl($currentUrl);
        OpenGraph::addProperty('type', 'article');
        OpenGraph::addImage($imageUrl);
        if ($googleUrl) {
            OpenGraph::addImage($googleUrl);
        }

        TwitterCard::setTitle($title);
        TwitterCard::setDescription($description);
        TwitterCard::setImage($googleUrl ?: $imageUrl);

        JsonLd::setType('ImageObject');
        JsonLd::setTitle($title);
        JsonLd::setDescription($description);
        JsonLd::setUrl($currentUrl);
        JsonLd::addValue('contentUrl', $imageUrl);
        if ($googleUrl) {
            JsonLd::addValue('sameAs', $googleUrl);
        }
        JsonLd::addValue('thumbnail', $this->image->getThumbnailUrl('thumb'));
        JsonLd::addValue('representativeOfPage', $this->image->is_cover);
        JsonLd::addValue('caption', $this->image->caption ?? $this->image->alt_text);
        
        if ($this->image->width && $this->image->height) {
            JsonLd::addValue('width', $this->image->width);
            JsonLd::addValue('height', $this->image->height);
        }
    }

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
