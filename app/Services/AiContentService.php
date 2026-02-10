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

Return a JSON object with ALL of the following keys (all required):
- "alt_text": A concise description (max 125 chars) of what's visible in the image. Include relevant keywords like the room type, materials, or features visible.
- "seo_alt_text": A concise SEO-optimized variant (max 125 chars). It can be similar to alt_text but should be slightly more keyword-rich if possible.
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
     * Generate an SEO-rich description for a project using all image AI content.
     */
    public function generateProjectDescription(Project $project): ?string
    {
        if (empty($this->apiKey)) {
            $this->lastError = 'Gemini API key not configured';
            return null;
        }

        $projectType = match($project->project_type) {
            'kitchen' => 'kitchen remodeling',
            'bathroom' => 'bathroom remodeling',
            'basement' => 'basement remodeling',
            'home-remodel' => 'whole home remodeling',
            'mudroom' => 'mudroom and laundry room',
            default => 'home remodeling',
        };

        $location = $project->location ?: 'the Chicago area';

        // Collect up to 5 representative images for vision context
        // Prioritize cover image first, then by sort order
        $allImages = $project->images()
            ->orderByDesc('is_cover')
            ->orderBy('sort_order')
            ->limit(5)
            ->get();

        $multiImageData = [];
        foreach ($allImages as $img) {
            $data = $this->getImageData($img);
            if ($data) {
                $multiImageData[] = $data;
            }
        }

        // Gather AI-generated content from ALL project images
        $imageDescriptions = $project->images()
            ->orderBy('sort_order')
            ->get()
            ->map(function ($img) {
                $parts = array_filter([
                    $img->getRawOriginal('seo_alt_text'),
                    $img->caption,
                ]);
                return implode(' — ', $parts);
            })
            ->filter()
            ->values();

        $imageContext = '';
        if ($imageDescriptions->isNotEmpty()) {
            $imageContext = "\n\nAI-generated descriptions from each project photo (use these details for accuracy):\n";
            foreach ($imageDescriptions as $i => $desc) {
                $imageContext .= ($i + 1) . ". {$desc}\n";
            }
        }

        $prompt = <<<PROMPT
You are an SEO copywriter for GS Construction, a home remodeling company in Chicago.
You are looking at {$allImages->count()} photos from this project. Study ALL the images carefully.

Write a compelling project description (2-3 sentences) that:
- Describes the type of work done ({$projectType})
- Mentions the location ({$location})
- References specific materials, features, or details visible across ALL the project photos
- Highlights quality craftsmanship
- Is SEO-optimized with relevant keywords
- Sounds professional but approachable

Project title: {$project->title}{$imageContext}

Return ONLY the description text, no JSON or formatting.
PROMPT;

        return $this->callGeminiMultiImage($prompt, $multiImageData);
    }

    /**
     * Call Gemini API with optional single image.
     */
    protected function callGemini(string $prompt, ?array $imageData = null): ?string
    {
        return $this->callGeminiMultiImage($prompt, $imageData ? [$imageData] : []);
    }

    /**
     * Call Gemini API with multiple images.
     */
    protected function callGeminiMultiImage(string $prompt, array $imagesData = [], int $maxOutputTokens = 500): ?string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $parts = [];

        // Add all images first
        foreach ($imagesData as $imageData) {
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
            $response = Http::timeout(120)
                ->acceptJson()
                ->post($url, [
                    'contents' => [
                        [
                            'parts' => $parts,
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => $maxOutputTokens,
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

        if (isset($decoded['seo_alt_text']) && is_string($decoded['seo_alt_text'])) {
            $result['seo_alt_text'] = mb_substr(trim($decoded['seo_alt_text']), 0, 125);
        }

        if (isset($decoded['caption']) && is_string($decoded['caption'])) {
            $result['caption'] = trim($decoded['caption']);
        }

        if (empty($result['seo_alt_text'])) {
            $this->lastError = 'Missing seo_alt_text in AI response: ' . $content;
            return null;
        }

        return !empty($result) ? $result : null;
    }

    /* ------------------------------------------------------------------ */
    /*  Social Media Content Generation                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Generate an Instagram/Facebook caption and hashtags for a project image.
     *
     * Uses Gemini vision to analyse the actual image and produce:
     *   - caption: engaging, on-brand social media caption with a CTA
     *   - hashtags: 15-25 relevant hashtags as a single string
     *
     * @return array{caption: string, hashtags: string}|null
     */
    public function generateSocialMediaContent(ProjectImage $image, string $linkUrl): ?array
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

        $imageData = $this->getImageData($image);
        if (!$imageData) {
            return null;
        }

        $projectContext = $this->buildProjectContext($project);

        $prompt = <<<PROMPT
You are a social media manager for GS Construction, a premium home remodeling company based in Chicago.
Analyze this project photo and generate an engaging Instagram/Facebook post.

Project Context:
{$projectContext}

Existing AI description of this image: {$image->caption}

Generate a JSON object with these keys:
1. "caption": An engaging social media caption (2-4 sentences). Requirements:
   - Start with something eye-catching (emoji optional, but keep it professional)
   - Describe what's shown in the photo with specific details (materials, colors, design choices)
   - Mention the location ({$project->location}) naturally
   - Include a call-to-action like "See the full project at {$linkUrl}" or "Link in bio"
   - Sound premium but approachable — like a proud craftsman showing off great work
   - Do NOT include hashtags in the caption

2. "hashtags": A string of 15-25 relevant hashtags separated by spaces. Mix of:
   - High-volume: #HomeRemodeling #InteriorDesign #HomeImprovement #Renovation
   - Location: #ChicagoContractor #ChicagoRemodeling #{$this->locationToHashtag($project->location)}
   - Project-specific: related to the room type, materials, style visible in the photo
   - Industry: #GeneralContractor #BeforeAndAfter #HomeDesign #LuxuryHome
   - Brand: #GSConstruction #GSCConstruction

Rules:
- Caption must be under 2000 characters
- No markdown formatting in the caption
- Hashtags should start with # and be separated by spaces
- Return ONLY valid JSON, no markdown code blocks

PROMPT;

        $response = $this->callGemini($prompt, $imageData);

        if ($response === null) {
            return null;
        }

        return $this->parseSocialMediaResponse($response);
    }

    /**
     * Parse the social media JSON response from Gemini.
     */
    protected function parseSocialMediaResponse(string $content): ?array
    {
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (!is_array($decoded) || empty($decoded['caption']) || empty($decoded['hashtags'])) {
            $this->lastError = 'Failed to parse social media JSON: ' . $content;
            return null;
        }

        return [
            'caption' => mb_substr(trim($decoded['caption']), 0, 2000),
            'hashtags' => trim($decoded['hashtags']),
        ];
    }

    /**
     * Convert a location string to a hashtag-friendly format.
     * "Palatine, IL" → "Palatine"
     */
    protected function locationToHashtag(?string $location): string
    {
        if (!$location) {
            return 'Chicago';
        }

        // Take the city part before any comma
        $city = trim(explode(',', $location)[0]);
        // Remove spaces and special chars
        return preg_replace('/[^A-Za-z0-9]/', '', $city);
    }
}
