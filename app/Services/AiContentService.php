<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class AiContentService
{
    protected string $apiKey;
    protected string $model;
    protected ?string $lastError = null;

    public function __construct()
    {
        $this->apiKey = config('services.google.gemini_api_key', '');
        $this->model = config('services.google.gemini_model', 'gemini-2.0-flash');
    }

    /**
     * Get the last error message.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Generate SEO-rich alt text and caption for an image using Gemini vision.
     */
    public function generateImageContent(ProjectImage $image): ?array
    {
        if (empty($this->apiKey)) {
            $this->lastError = 'Gemini API key not configured';
            return null;
        }

        $project = $image->project;
        if (!$project) {
            $this->lastError = 'Image has no associated project';
            return null;
        }

        // Get the image data
        $imageData = $this->getImageData($image);
        if (!$imageData) {
            return null;
        }

        $projectContext = $this->buildProjectContext($project);

        $prompt = <<<PROMPT
You are an SEO expert for a home remodeling company called GS Construction based in Chicago.
Generate concise, descriptive, SEO-optimized content for this project image.

Project Context:
{$projectContext}

Return a JSON object with:
- "alt_text": A concise description (max 125 chars) of what's visible in the image. Include relevant keywords like the room type, materials, or features visible.
- "caption": A longer SEO-rich description (1-2 sentences) that describes the work shown, mentions the location if known, and includes relevant keywords. Make it engaging but factual.

Rules:
- Focus on what's actually visible in the image
- Include specific details like cabinet styles, countertop materials, flooring types, fixtures, etc.
- Mention the project location if provided
- Keep alt_text under 125 characters
- Return ONLY valid JSON, no markdown code blocks or extra text

Analyze this home remodeling project photo and generate SEO-optimized alt text and caption.
PROMPT;

        $response = $this->callGemini($prompt, $imageData);
        
        if ($response === null) {
            return null;
        }

        return $this->parseJsonResponse($response);
    }

    /**
     * Generate an SEO-rich description for a project.
     */
    public function generateProjectDescription(Project $project): ?string
    {
        if (empty($this->apiKey)) {
            $this->lastError = 'Gemini API key not configured';
            return null;
        }

        // Get cover image if available
        $coverImage = $project->images()->where('is_cover', true)->first()
            ?? $project->images()->orderBy('sort_order')->first();
        
        $imageData = $coverImage ? $this->getImageData($coverImage) : null;

        $projectType = match($project->project_type) {
            'kitchen' => 'kitchen remodeling',
            'bathroom' => 'bathroom remodeling',
            'basement' => 'basement remodeling',
            'home-remodel' => 'whole home remodeling',
            'mudroom' => 'mudroom and laundry room',
            default => 'home remodeling',
        };

        $location = $project->location ?: 'the Chicago area';

        $prompt = <<<PROMPT
You are an SEO copywriter for GS Construction, a home remodeling company in Chicago.
Write a compelling project description (2-3 sentences) that:
- Describes the type of work done ({$projectType})
- Mentions the location ({$location})
- Highlights quality craftsmanship
- Is SEO-optimized with relevant keywords
- Sounds professional but approachable

Project title: {$project->title}

Return ONLY the description text, no JSON or formatting.
PROMPT;

        return $this->callGemini($prompt, $imageData);
    }

    /**
     * Call Gemini API with optional image.
     */
    protected function callGemini(string $prompt, ?array $imageData = null): ?string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $parts = [];

        // Add image first if provided
        if ($imageData) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $imageData['mime_type'],
                    'data' => $imageData['base64'],
                ],
            ];
        }

        // Add text prompt
        $parts[] = ['text' => $prompt];

        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->post($url, [
                    'contents' => [
                        [
                            'parts' => $parts,
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 300,
                    ],
                ]);
        } catch (ConnectionException $e) {
            $this->lastError = 'Connection error: ' . $e->getMessage();
            return null;
        }

        if (!$response->successful()) {
            $errorMessage = $response->json('error.message') ?? $response->body();
            $this->lastError = 'API error ' . $response->status() . ': ' . $errorMessage;
            return null;
        }

        $content = $response->json('candidates.0.content.parts.0.text');
        
        if (!is_string($content) || trim($content) === '') {
            $this->lastError = 'Empty response from API';
            return null;
        }

        return trim($content);
    }

    /**
     * Get image data as base64 for Gemini API.
     */
    protected function getImageData(ProjectImage $image): ?array
    {
        $disk = config('app.images_disk', 'public');
        $filePath = $image->path;
        
        // Try thumbnail first, fall back to original
        $thumbnails = $image->thumbnails ?? [];
        $thumbPath = $thumbnails['large'] ?? null;
        
        if ($thumbPath && Storage::disk($disk)->exists($thumbPath)) {
            $filePath = $thumbPath;
        }
        
        if (!Storage::disk($disk)->exists($filePath)) {
            $this->lastError = 'Image file not found: ' . $filePath;
            return null;
        }

        $contents = Storage::disk($disk)->get($filePath);
        $mimeType = Storage::disk($disk)->mimeType($filePath);
        
        return [
            'base64' => base64_encode($contents),
            'mime_type' => $mimeType,
        ];
    }

    protected function buildProjectContext(Project $project): string
    {
        $parts = [];
        $parts[] = "Project: {$project->title}";
        
        if ($project->project_type) {
            $type = match($project->project_type) {
                'kitchen' => 'Kitchen Remodel',
                'bathroom' => 'Bathroom Remodel',
                'basement' => 'Basement Remodel',
                'home-remodel' => 'Whole Home Remodel',
                'mudroom' => 'Mudroom/Laundry Room',
                default => ucfirst($project->project_type),
            };
            $parts[] = "Type: {$type}";
        }

        if ($project->location) {
            $parts[] = "Location: {$project->location}";
        }

        if ($project->description) {
            $parts[] = "Description: {$project->description}";
        }

        return implode("\n", $parts);
    }

    protected function parseJsonResponse(string $content): ?array
    {
        // Remove markdown code blocks if present
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            $this->lastError = 'Failed to parse JSON response: ' . $content;
            return null;
        }

        $result = [];

        if (isset($decoded['alt_text']) && is_string($decoded['alt_text'])) {
            // Ensure alt_text is under 125 chars
            $result['alt_text'] = mb_substr(trim($decoded['alt_text']), 0, 125);
        }

        if (isset($decoded['caption']) && is_string($decoded['caption'])) {
            $result['caption'] = trim($decoded['caption']);
        }

        return !empty($result) ? $result : null;
    }
}
