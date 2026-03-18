<?php

namespace App\Livewire\Admin;

use App\Jobs\ProcessProjectImage;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\ProjectBeforeAfter;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
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
    public bool $is_published = true;

    // File uploads
    #[Validate(['uploads.*' => 'image|max:51200'])] // 50MB max per image
    public array $uploads = [];

    // Accumulated uploads across multiple drops
    public array $allUploads = [];

    // Track duplicate upload indices (relative to $allUploads)
    public array $duplicateIndices = [];

    // Existing images
    public array $existingImages = [];

    // Bulk image selection/tagging
    public array $selectedImageIds = [];
    public ?int $bulkTagId = null;
    public string $tagSearch = '';

    // Timelapse management
    // Each entry: ['id' => ?int, 'title' => string, 'uploads' => [], 'allUploads' => [], 'existingFrames' => []]
    public array $timelapses = [];
    #[Validate(['timelapseUploads.*' => 'image|max:51200'])]
    public array $timelapseUploads = [];
    public ?int $activeTimelapseIndex = null;

    // Before/After management
    // Each entry: ['id' => ?int, 'title' => string, 'beforeUrl' => ?string, 'afterUrl' => ?string]
    public array $beforeAfters = [];
    // Separate upload arrays keyed by BA index so Livewire can serialize them
    public array $baBeforeUploads = [];
    public array $baAfterUploads = [];
    public ?int $activeBaIndex = null;

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
            $this->loadExistingTimelapses();
            $this->loadExistingBeforeAfters();
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
                'tags' => $img->tags
                    ->map(fn($tag) => ['id' => $tag->id, 'name' => $tag->name])
                    ->toArray(),
            ])
            ->toArray();

        if (!empty($this->selectedImageIds)) {
            $existingIds = array_column($this->existingImages, 'id');
            $this->selectedImageIds = array_values(array_intersect($this->selectedImageIds, $existingIds));
        }
    }

    protected function loadExistingTimelapses(): void
    {
        if (!$this->project) return;

        $this->timelapses = $this->project->timelapses()
            ->with('frames')
            ->orderBy('sort_order')
            ->get()
            ->map(fn($tl) => [
                'id' => $tl->id,
                'title' => $tl->title ?? '',
                'display_mode' => $tl->display_mode ?? 'slider',
                'allUploads' => [],
                'existingFrames' => $tl->frames->map(fn($f) => [
                    'id' => $f->id,
                    'url' => $f->url,
                    'original_filename' => $f->original_filename,
                ])->toArray(),
            ])
            ->toArray();
    }

    // Before/After methods
    protected function loadExistingBeforeAfters(): void
    {
        if (!$this->project) return;

        $this->beforeAfters = $this->project->beforeAfters()
            ->orderBy('sort_order')
            ->get()
            ->map(fn($ba) => [
                'id' => $ba->id,
                'title' => $ba->title ?? '',
                'beforeUrl' => $ba->before_url,
                'afterUrl' => $ba->after_url,
            ])
            ->toArray();
        $this->baBeforeUploads = [];
        $this->baAfterUploads = [];
    }

    public function addBeforeAfter(): void
    {
        $this->beforeAfters[] = [
            'id' => null,
            'title' => '',
            'beforeUrl' => null,
            'afterUrl' => null,
        ];
    }

    public function removeBeforeAfter(int $index): void
    {
        $ba = $this->beforeAfters[$index] ?? null;
        if (!$ba) return;

        if ($ba['id']) {
            $model = ProjectBeforeAfter::find($ba['id']);
            $model?->delete();
        }

        unset($this->beforeAfters[$index]);
        $this->beforeAfters = array_values($this->beforeAfters);

        // Re-key upload arrays to match
        $newBefore = [];
        $newAfter = [];
        $i = 0;
        foreach (array_keys($this->beforeAfters) as $newIdx) {
            $oldIdx = $newIdx >= $index ? $newIdx + 1 : $newIdx;
            if (isset($this->baBeforeUploads[$oldIdx])) $newBefore[$newIdx] = $this->baBeforeUploads[$oldIdx];
            if (isset($this->baAfterUploads[$oldIdx])) $newAfter[$newIdx] = $this->baAfterUploads[$oldIdx];
        }
        $this->baBeforeUploads = $newBefore;
        $this->baAfterUploads = $newAfter;
    }

    public function updatedBaBeforeUploads($value, $key): void
    {
        $this->validateOnly("baBeforeUploads.{$key}", [
            "baBeforeUploads.{$key}" => 'image|max:51200',
        ]);
    }

    public function updatedBaAfterUploads($value, $key): void
    {
        $this->validateOnly("baAfterUploads.{$key}", [
            "baAfterUploads.{$key}" => 'image|max:51200',
        ]);
    }

    public function addTimelapse(): void
    {
        $this->timelapses[] = [
            'id' => null,
            'title' => '',
            'display_mode' => 'slider',
            'allUploads' => [],
            'existingFrames' => [],
        ];
    }

    public function removeTimelapse(int $index): void
    {
        $timelapse = $this->timelapses[$index] ?? null;
        if (!$timelapse) return;

        // Delete from DB if it exists
        if ($timelapse['id']) {
            ProjectTimelapse::where('id', $timelapse['id'])
                ->where('project_id', $this->project?->id)
                ->delete();
        }

        unset($this->timelapses[$index]);
        $this->timelapses = array_values($this->timelapses);
    }

    public function updatedTimelapseUploads(): void
    {
        foreach (array_keys($this->timelapseUploads) as $index) {
            $this->validateOnly("timelapseUploads.{$index}");
        }

        if ($this->activeTimelapseIndex === null) return;

        if (!isset($this->timelapses[$this->activeTimelapseIndex])) return;

        foreach ($this->timelapseUploads as $upload) {
            $this->timelapses[$this->activeTimelapseIndex]['allUploads'][] = $upload;
        }

        $this->timelapseUploads = [];
    }

    public function setActiveTimelapseIndex(int $index): void
    {
        $this->activeTimelapseIndex = $index;
    }

    public function removeQueuedTimelapseUpload(int $timelapseIndex, int $uploadIndex): void
    {
        if (!isset($this->timelapses[$timelapseIndex]['allUploads'][$uploadIndex])) return;

        unset($this->timelapses[$timelapseIndex]['allUploads'][$uploadIndex]);
        $this->timelapses[$timelapseIndex]['allUploads'] = array_values($this->timelapses[$timelapseIndex]['allUploads']);
    }

    public function removeTimelapseFrame(int $timelapseIndex, int $frameId): void
    {
        $timelapse = $this->timelapses[$timelapseIndex] ?? null;
        if (!$timelapse || !$timelapse['id']) return;

        $frame = ProjectTimelapseFrame::find($frameId);
        if ($frame && $frame->project_timelapse_id === $timelapse['id']) {
            $frame->delete();
            // Refresh existing frames
            $this->timelapses[$timelapseIndex]['existingFrames'] = array_values(
                array_filter($this->timelapses[$timelapseIndex]['existingFrames'], fn($f) => $f['id'] !== $frameId)
            );
        }
    }

    public function reorderTimelapseFrames(int $timelapseIndex, array $orderedIds): void
    {
        $timelapse = $this->timelapses[$timelapseIndex] ?? null;
        if (!$timelapse || !$timelapse['id']) return;

        foreach ($orderedIds as $sort => $id) {
            ProjectTimelapseFrame::where('id', $id)
                ->where('project_timelapse_id', $timelapse['id'])
                ->update(['sort_order' => $sort]);
        }

        // Re-sort existing frames in memory
        $frameMap = collect($this->timelapses[$timelapseIndex]['existingFrames'])->keyBy('id');
        $this->timelapses[$timelapseIndex]['existingFrames'] = collect($orderedIds)
            ->map(fn($id) => $frameMap->get($id))
            ->filter()
            ->values()
            ->toArray();
    }

    public function updatedUploads(): void
    {
        // Validate each new file so oversize/invalid images show an error immediately.
        foreach (array_keys($this->uploads) as $index) {
            $this->validateOnly("uploads.{$index}");
        }

        // Merge new uploads into the accumulated collection and detect duplicates
        $this->mergeUploads();
    }

    /**
     * Append new uploads to $allUploads, detect duplicates, and clear $uploads
     * so the dropzone is immediately ready for more files.
     */
    protected function mergeUploads(): void
    {
        $existingFilenames = $this->getExistingImageFilenames();

        // Collect filenames already queued
        $seenFilenames = [];
        foreach ($this->allUploads as $upload) {
            try {
                $seenFilenames[$upload->getClientOriginalName()] = true;
            } catch (\Exception $e) {}
        }

        foreach ($this->uploads as $upload) {
            try {
                $filename = $upload->getClientOriginalName();
                $newIndex = count($this->allUploads);
                $this->allUploads[] = $upload;

                if (in_array($filename, $existingFilenames) || isset($seenFilenames[$filename])) {
                    $this->duplicateIndices[] = $newIndex;
                }

                $seenFilenames[$filename] = true;
            } catch (\Exception $e) {
                $this->allUploads[] = $upload;
            }
        }

        // Clear uploads so the dropzone accepts more files
        $this->uploads = [];
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

    public function removeQueuedUpload(int $index): void
    {
        if (!isset($this->allUploads[$index])) {
            return;
        }

        unset($this->allUploads[$index]);
        $this->allUploads = array_values($this->allUploads);
        $this->rebuildDuplicateIndices();
    }

    protected function rebuildDuplicateIndices(): void
    {
        $existingFilenames = $this->getExistingImageFilenames();
        $seenFilenames = [];
        $this->duplicateIndices = [];

        foreach ($this->allUploads as $index => $upload) {
            try {
                $filename = $upload->getClientOriginalName();
                if (in_array($filename, $existingFilenames) || isset($seenFilenames[$filename])) {
                    $this->duplicateIndices[] = $index;
                }
                $seenFilenames[$filename] = true;
            } catch (\Exception $e) {}
        }
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

    public function selectAllImages(): void
    {
        $this->selectedImageIds = array_column($this->existingImages, 'id');
    }

    public function clearSelectedImages(): void
    {
        $this->selectedImageIds = [];
    }

    public function toggleImageSelection(int $imageId): void
    {
        $key = array_search($imageId, $this->selectedImageIds, true);

        if ($key === false) {
            $this->selectedImageIds[] = $imageId;
            return;
        }

        unset($this->selectedImageIds[$key]);
        $this->selectedImageIds = array_values($this->selectedImageIds);
    }

    public function selectBulkTag(int $tagId): void
    {
        $this->bulkTagId = $tagId;
    }

    public function createTagFromSearch(): void
    {
        $name = trim($this->tagSearch);

        if ($name === '') {
            return;
        }

        $tag = Tag::query()->where('name', $name)->first();

        if (!$tag) {
            $tag = Tag::create([
                'name' => $name,
                'type' => 'general',
            ]);
        }

        $this->bulkTagId = $tag->id;
        $this->tagSearch = '';
        $this->dispatch('tag-created');
    }

    public function assignTagToSelected(): void
    {
        if (!$this->project?->id || empty($this->selectedImageIds) || !$this->bulkTagId) {
            return;
        }

        $images = ProjectImage::query()
            ->where('project_id', $this->project->id)
            ->whereIn('id', $this->selectedImageIds)
            ->get();

        foreach ($images as $image) {
            $image->tags()->syncWithoutDetaching([$this->bulkTagId]);
        }

        $this->bulkTagId = null;
        $this->clearSelectedImages();
        $this->loadExistingImages();
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
        if (!empty($this->allUploads)) {
            $sortOrder = $project->images()->max('sort_order') ?? 0;
            $isFirstImage = $project->images()->count() === 0;

            foreach ($this->allUploads as $index => $upload) {
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

            $this->allUploads = [];
            $this->duplicateIndices = [];
        }

        // Save timelapses
        foreach ($this->timelapses as $tlIndex => $tlData) {
            if ($tlData['id']) {
                // Update existing timelapse
                $timelapse = ProjectTimelapse::find($tlData['id']);
                if ($timelapse) {
                    $timelapse->update([
                        'title' => $tlData['title'] ?: null,
                        'display_mode' => $tlData['display_mode'] ?? 'slider',
                        'sort_order' => $tlIndex,
                    ]);
                }
            } else {
                // Create new timelapse
                $timelapse = ProjectTimelapse::create([
                    'project_id' => $project->id,
                    'title' => $tlData['title'] ?: null,
                    'display_mode' => $tlData['display_mode'] ?? 'slider',
                    'sort_order' => $tlIndex,
                ]);
                $this->timelapses[$tlIndex]['id'] = $timelapse->id;
            }

            // Upload new frames for this timelapse
            if (!empty($tlData['allUploads'])) {
                $sortOrder = $timelapse->frames()->max('sort_order') ?? 0;
                $basePath = 'projects/' . $project->id . '/timelapse/' . $timelapse->id;

                foreach ($tlData['allUploads'] as $upload) {
                    $sortOrder++;
                    $extension = $upload->getClientOriginalExtension();
                    $filename = $sortOrder . '_' . \Illuminate\Support\Str::random(8) . '.' . $extension;

                    // Resize frame like project images (max 1920px wide, quality 80)
                    $image = \Intervention\Image\Laravel\Facades\Image::read($upload);
                    if ($image->width() > 1920) {
                        $image->scale(width: 1920);
                    }
                    $encoded = match (strtolower($extension)) {
                        'png' => $image->toPng()->toString(),
                        'webp' => $image->toWebp(80)->toString(),
                        default => $image->toJpeg(80)->toString(),
                    };

                    $path = $basePath . '/' . $filename;
                    \Illuminate\Support\Facades\Storage::disk('public')->put($path, $encoded);

                    ProjectTimelapseFrame::create([
                        'project_timelapse_id' => $timelapse->id,
                        'filename' => $filename,
                        'original_filename' => $upload->getClientOriginalName(),
                        'path' => $path,
                        'disk' => 'public',
                        'sort_order' => $sortOrder,
                    ]);
                }

                $this->timelapses[$tlIndex]['allUploads'] = [];
            }
        }

        // Save before/afters
        foreach ($this->beforeAfters as $baIndex => $baData) {
            $basePath = 'projects/' . $project->id . '/before-after';
            $beforeUpload = $this->baBeforeUploads[$baIndex] ?? null;
            $afterUpload = $this->baAfterUploads[$baIndex] ?? null;

            if ($baData['id']) {
                $model = ProjectBeforeAfter::find($baData['id']);
                if (!$model) continue;
                $model->update(['title' => $baData['title'] ?: null, 'sort_order' => $baIndex]);
            } else {
                // Need both images for a new entry
                if (!$beforeUpload || !$afterUpload) continue;
                $model = ProjectBeforeAfter::create([
                    'project_id' => $project->id,
                    'title' => $baData['title'] ?: null,
                    'before_path' => '',
                    'after_path' => '',
                    'sort_order' => $baIndex,
                ]);
                $this->beforeAfters[$baIndex]['id'] = $model->id;
            }

            // Process before image upload
            if ($beforeUpload) {
                $old = $model->before_path;
                $filename = 'before_' . $model->id . '_' . \Illuminate\Support\Str::random(8) . '.' . $beforeUpload->getClientOriginalExtension();
                $image = \Intervention\Image\Laravel\Facades\Image::read($beforeUpload);
                if ($image->width() > 1920) {
                    $image->scale(width: 1920);
                }
                $ext = strtolower($beforeUpload->getClientOriginalExtension());
                $encoded = match ($ext) {
                    'png' => $image->toPng()->toString(),
                    'webp' => $image->toWebp(80)->toString(),
                    default => $image->toJpeg(80)->toString(),
                };
                $path = $basePath . '/' . $filename;
                \Illuminate\Support\Facades\Storage::disk('public')->put($path, $encoded);
                if ($old && $old !== $path) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($old);
                }
                $model->update(['before_path' => $path]);
            }

            // Process after image upload
            if ($afterUpload) {
                $old = $model->after_path;
                $filename = 'after_' . $model->id . '_' . \Illuminate\Support\Str::random(8) . '.' . $afterUpload->getClientOriginalExtension();
                $image = \Intervention\Image\Laravel\Facades\Image::read($afterUpload);
                if ($image->width() > 1920) {
                    $image->scale(width: 1920);
                }
                $ext = strtolower($afterUpload->getClientOriginalExtension());
                $encoded = match ($ext) {
                    'png' => $image->toPng()->toString(),
                    'webp' => $image->toWebp(80)->toString(),
                    default => $image->toJpeg(80)->toString(),
                };
                $path = $basePath . '/' . $filename;
                \Illuminate\Support\Facades\Storage::disk('public')->put($path, $encoded);
                if ($old && $old !== $path) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($old);
                }
                $model->update(['after_path' => $path]);
            }
        }

        $this->baBeforeUploads = [];
        $this->baAfterUploads = [];

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
