<?php

namespace App\Livewire\Admin;

use App\Jobs\ProcessProjectImage;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\Tag;
use App\Services\ImageService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.admin')]
#[Title('Project')]
class ProjectForm extends Component
{
    use WithFileUploads;

    public ?Project $project = null;

    #[Validate('required|min:3')]
    public string $title = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('required')]
    public string $project_type = 'kitchen';

    #[Validate('nullable|string')]
    public string $location = '';

    #[Validate('nullable|date_format:Y-m')]
    public ?string $completed_month = null;

    public bool $is_featured = false;
    public bool $is_published = false;

    // File uploads
    #[Validate(['uploads.*' => 'image|max:10240'])] // 10MB max per image
    public array $uploads = [];

    // Track duplicate upload indices
    public array $duplicateIndices = [];

    // Existing images
    public array $existingImages = [];

    // Tag management
    public array $selectedTags = [];
    public string $newTagName = '';
    public string $newTagType = 'general';

    public function mount(?Project $project = null): void
    {
        if ($project && $project->exists) {
            $this->project = $project;
            $this->title = $project->title;
            $this->description = $project->description ?? '';
            $this->project_type = $project->project_type;
            $this->location = $project->location ?? '';
            $this->completed_month = $project->completed_at?->format('Y-m');
            $this->is_featured = $project->is_featured;
            $this->is_published = $project->is_published;
            
            $this->loadExistingImages();
        }
    }

    protected function loadExistingImages(): void
    {
        if (!$this->project) return;

        $this->existingImages = $this->project->images()
            ->with('tags')
            ->orderBy('sort_order')
            ->get()
            ->map(fn($img) => [
                'id' => $img->id,
                'url' => $img->getThumbnailUrl('small'),
                'alt_text' => $img->alt_text,
                'is_cover' => $img->is_cover,
                'tags' => $img->tags->pluck('id')->toArray(),
            ])
            ->toArray();
    }

    public function updatedUploads(): void
    {
        // Validate each file so oversize/invalid images show an error immediately.
        foreach (array_keys($this->uploads) as $index) {
            $this->validateOnly("uploads.{$index}");
        }

        // Remove duplicates based on file content hash
        $this->removeDuplicateUploads();
    }

    /**
     * Detect duplicate uploads based on original filename.
     * Marks duplicates instead of removing them.
     */
    protected function removeDuplicateUploads(): void
    {
        $seenFilenames = [];
        $this->duplicateIndices = [];

        // Get original filenames of existing images for this project
        $existingFilenames = $this->getExistingImageFilenames();

        foreach ($this->uploads as $index => $upload) {
            try {
                $filename = $upload->getClientOriginalName();
                
                // Mark as duplicate if matches existing project image or already seen
                if (in_array($filename, $existingFilenames) || isset($seenFilenames[$filename])) {
                    $this->duplicateIndices[] = $index;
                } else {
                    $seenFilenames[$filename] = true;
                }
            } catch (\Exception $e) {
                // If we can't read info, don't mark as duplicate
            }
        }
    }

    /**
     * Get original filenames of existing images for this project.
     */
    protected function getExistingImageFilenames(): array
    {
        if (!$this->project) {
            return [];
        }

        return $this->project->images()
            ->pluck('original_filename')
            ->toArray();
    }

    public function removeExistingImage(int $imageId): void
    {
        $image = ProjectImage::find($imageId);
        if ($image && $image->project_id === $this->project?->id) {
            $image->delete();
            $this->loadExistingImages();
        }
    }

    public function setCoverImage(int $imageId): void
    {
        $image = ProjectImage::find($imageId);
        if ($image && $image->project_id === $this->project?->id) {
            app(ImageService::class)->setCover($image);
            $this->loadExistingImages();
        }
    }

    public function updateImageAlt(int $imageId, string $altText): void
    {
        $image = ProjectImage::find($imageId);
        if ($image && $image->project_id === $this->project?->id) {
            $image->update(['alt_text' => $altText]);
        }
    }

    public function toggleImageTag(int $imageId, int $tagId): void
    {
        $image = ProjectImage::find($imageId);
        if ($image && $image->project_id === $this->project?->id) {
            $image->tags()->toggle($tagId);
            $this->loadExistingImages();
        }
    }

    public function createTag(): void
    {
        $this->validate([
            'newTagName' => 'required|min:2',
            'newTagType' => 'required',
        ]);

        Tag::create([
            'name' => $this->newTagName,
            'type' => $this->newTagType,
        ]);

        $this->newTagName = '';
        $this->dispatch('tag-created');
    }

    public function save(): void
    {
        $this->validate();

        $completedAt = null;
        if ($this->completed_month) {
            $completedAt = Carbon::createFromFormat('Y-m', $this->completed_month)->startOfMonth();
        }

        $data = [
            'title' => $this->title,
            'description' => $this->description ?: null,
            'project_type' => $this->project_type,
            'location' => $this->location ?: null,
            'completed_at' => $completedAt,
            'is_featured' => $this->is_featured,
            'is_published' => $this->is_published,
        ];

        if ($this->project?->exists) {
            $this->project->update($data);
            $project = $this->project;
        } else {
            $project = Project::create($data);
        }

        // Upload new images (skip duplicates) - dispatch to queue for background processing
        if (!empty($this->uploads)) {
            $sortOrder = $project->images()->max('sort_order') ?? 0;
            $isFirstImage = $project->images()->count() === 0;

            foreach ($this->uploads as $index => $upload) {
                // Skip duplicates
                if (in_array($index, $this->duplicateIndices)) {
                    continue;
                }
                
                $sortOrder++;
                
                // Get the full filesystem path to the temp file
                // getRealPath() returns the absolute path from TemporaryUploadedFile
                $tempPath = $upload->getRealPath();
                
                // Dispatch job to process image in background
                ProcessProjectImage::dispatch(
                    projectId: $project->id,
                    tempPath: $tempPath,
                    originalFilename: $upload->getClientOriginalName(),
                    mimeType: $upload->getMimeType(),
                    sortOrder: $sortOrder,
                    isCover: $isFirstImage && $sortOrder === 1,
                );
            }

            $this->uploads = [];
            $this->duplicateIndices = [];
        }

        $message = $this->project?->exists 
            ? 'Project updated successfully.' 
            : 'Project created successfully. Images are processing in the background.';
        
        session()->flash('success', $message);
        
        $this->redirect(route('admin.projects.edit', $project), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.project-form', [
            'projectTypes' => Project::projectTypes(),
            'allTags' => Tag::orderBy('type')->orderBy('name')->get(),
            'tagTypes' => Tag::tagTypes(),
        ]);
    }
}
