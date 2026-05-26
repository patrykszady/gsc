<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
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
     * Rewrite an existing caption as a keyword-heavy SEO caption capped at $limit chars.
     * Used for short-form social/listing captions (Yelp = 140 chars).
     * Returns null on failure so caller can fall back to truncation.
     */
    public function shortenCaptionForSeo(ProjectImage $image, int $limit = 140): ?string
    {
        if (empty($this->apiKey)) {
            $this->lastError = 'Gemini API key not configured';
            return null;
        }

        $project = $image->project;
        $original = trim((string) ($image->caption ?? ''));
        if ($original === '') {
            $original = trim((string) ($image->seo_alt_text ?? $image->alt_text ?? ''));
        }
        if ($original === '') {
            $original = trim((string) ($project?->title ?? 'Home remodeling project'));
        }

        // v9 = source-driven, shorter, no formulaic tail, materials still banned.
        $cacheKey = "yelp_caption_seo:v9:{$image->id}:{$limit}:" . md5($original);
        return Cache::remember($cacheKey, now()->addDays(30), function () use ($image, $project, $original, $limit) {
            $type = $project?->project_type
                ? ucwords(str_replace(['-', '_'], ' ', (string) $project->project_type))
                : 'home remodel';
            // City only — strip any ", IL" / state code so we don't burn chars.
            $rawLocation = trim((string) ($project?->location ?? ''));
            $rawLocation = preg_replace('/\s*,\s*[A-Z]{2}\b.*$/', '', $rawLocation) ?? $rawLocation;
            $location = $rawLocation !== '' ? $rawLocation : 'Chicago suburbs';
            $title = trim((string) ($project?->title ?? ''));
            // Lower min so Gemini can write simpler, shorter sentences when the
            // source caption is brief. Cap is still the hard 140.
            $minChars = max(90, $limit - 50);

            $prompt = <<<PROMPT
Shorten and lightly rewrite the SOURCE CAPTION as a single Yelp business photo caption for GS Construction.

GOAL: A natural-sounding photo caption that describes THIS specific image. It should sound like a human posting a project photo — not ad copy, not a tag dump, not a corporate services blurb. Keep the SOURCE CAPTION's specific language about the work; just compress it and add local-SEO keywords where they fit naturally.

WRITING APPROACH:
- Start from the SOURCE CAPTION. Keep its concrete, image-specific phrases verbatim when possible (the room/area, what was done, the phase). Strip filler, marketing words, and any material/finish/color mentions.
- Then weave in: the city "{$location}" (twice), the project type "{$type}" (twice with two different service-variant words), and "GS Construction" (once). These should flow as part of a normal sentence, NOT be tacked on at the end as a keyword tail.
- Aim for {$minChars}-{$limit} characters. Shorter is fine if the source is short. Do not pad with marketing language to hit the cap.

HARD RULES:
- ONE complete grammatical sentence. Starts with a capital letter, ends with a period. Real subject + verb. No fragments, no colon-then-list, no em-dash-then-list, no comma-spliced tag dumps.
- No line breaks, hashtags, emojis, quotes, or exclamation points.
- Max length: {$limit} characters (count every space and punctuation mark).
- Mention the city "{$location}" exactly twice. Do NOT include the state ("IL", "Illinois") or "Chicago" / "Chicago area".
- Mention "GS Construction" exactly once.
- Mention the project type "{$type}" twice, each time paired with a DIFFERENT service-variant word (e.g. "kitchen remodel" + "kitchen renovation", "mudroom remodel" + "mudroom renovation").
- KEYWORD VARIETY (CRITICAL — NO REPEATS): each of these service-variant words may appear LITERALLY AT MOST ONCE — "remodel", "remodeling", "renovation", "renovate", "contractor", "remodeler", "design-build", "finishing". Treat morphological variants as the same word ("remodel"="remodels"). At least TWO different variants total.
- VOICE: GS Construction IS the remodeler / contractor. NEVER write "our remodeler", "our contractor", "our remodeling contractor", "our design-build contractor", or "our remodelers". Allowed: "we", "we handle", "as your {$location} remodeler", "as a design-build contractor", "our team", "our crew", "our design-build team". Also AVOID the formulaic tail "part of our [city] [type] renovation as a design-build team" — it sounds robotic. Prefer integrating the keywords into the main clause.
- Do NOT mention materials, finishes, colors, fixtures, brand names, or cosmetic appearance (no "white cabinets", "quartz countertops", "hardwood floors", "tile", "marble", "stainless steel", "shaker", "matte black", "subway tile", etc.). Talk about WHAT was done, WHERE in the home, and WHAT PHASE — never how it looks.
- Do NOT use these filler words: stunning, beautiful, modern, gorgeous, dream, transform, create, breathtaking, amazing, perfect, sleek, elegant.
- Do NOT invent facts not present in the SOURCE CAPTION or context. If the source is thin, keep the caption short rather than fabricating detail.

IMAGE-SPECIFIC DETAIL (no materials):
Use 2-4 concrete, NON-COSMETIC details from the SOURCE CAPTION. Examples of allowed details:
- Room/area: "primary bath", "powder room", "kitchen island", "mudroom bench", "basement bar", "laundry alcove", "pantry wall", "stair landing".
- Scope of work: "load-bearing wall removed", "layout reconfigured", "ceiling raised", "opened sightlines", "wall taken down", "doorway widened", "plumbing relocated", "built-in lockers added", "island enlarged", "floor plan opened up".
- Phase: "framing stage", "demo day", "rough-in", "final reveal", "before tear-out", "mid-renovation", "punch-list day".
- Homeowner angle: "for a Palatine family", "for empty-nesters", "first-time clients".
NOT allowed (these are cosmetic): cabinet colors, countertop materials, tile patterns, paint, hardware finishes, lighting style, flooring material.

GOOD EXAMPLES (study the voice — natural, source-driven, no awkward tail):
- "Mudroom bench and built-in lockers framed up on this Inverness mudroom remodel by GS Construction, a full Inverness mudroom renovation we handled end-to-end."
- "Load-bearing wall coming down on a Palatine kitchen remodel, opening up the floor plan — GS Construction is the design-build contractor on this Palatine kitchen renovation."
- "Demo day on a Schaumburg primary-bath gut, the start of a Schaumburg bathroom remodel GS Construction is handling as the design-build contractor for the full bathroom renovation."
- "Framing-stage view of a Hoffman Estates basement remodel with the load-bearing wall reframed by GS Construction, our Hoffman Estates basement finishing job in progress."
- "Final reveal of a Palatine kitchen remodel after we reconfigured the layout, a Palatine kitchen renovation GS Construction completed as a design-build team."

BANNED EXAMPLES (do NOT return anything like these):
- "GS Construction Mudroom renovation in Inverness: custom built-ins, full-service remodeling contractor for Inverness Mudroom remodeling" — colon-then-list fragment, "remodeling" repeats.
- "GS Construction completed a Palatine kitchen remodel with a new island layout, and we handle every Palatine kitchen renovation as a design-build contractor." — generic ad copy; the image-specific portion is too thin.
- "New island layout and opened-up sightlines on a Palatine kitchen remodel by GS Construction, part of our Palatine kitchen renovation as a design-build team." — awkward formulaic tail ("part of our X renovation as a design-build team").
- Any caption mentioning materials, colors, finishes, tile, countertops, paint, or hardware.

CONTEXT:
- Business: GS Construction (home remodeling company)
- Project type: {$type}
- City: {$location}
- Project title: {$title}

SOURCE CAPTION:
{$original}

Return ONLY the rewritten caption text on a single line. No JSON, no labels, no quotes.
PROMPT;

            $raw = $this->callGeminiMultiImage($prompt, [], 200);
            if (!is_string($raw) || trim($raw) === '') {
                return null;
            }
            $clean = trim($raw);
            // Strip wrapping quotes if Gemini added them anyway.
            $clean = trim($clean, "\"'`\n\r\t ");
            // Collapse whitespace to a single line.
            $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
            if (mb_strlen($clean) > $limit) {
                // Try to cut at last word boundary within limit.
                $cut = mb_substr($clean, 0, $limit);
                $space = mb_strrpos($cut, ' ');
                if ($space !== false && $space > $limit - 30) {
                    $cut = rtrim(mb_substr($cut, 0, $space), " ,.;:-");
                }
                $clean = $cut;
            }
            return $clean !== '' ? $clean : null;
        });
    }

    /**
     * Get image data as base64 for Gemini API.
     */
    protected function getImageData(ProjectImage $image): ?array
    {
        $disk = $image->disk ?: 'public';
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

    /**
     * Generate per-city SEO content (intro, local_intro, landmarks, permit_notes)
     * for an AreaServed row. Returns an associative array on success, or null on error.
     *
     * The prompt is grounded: it tells Gemini to use only well-known facts about the
     * Chicago suburb and to keep tone honest. Always review output before saving.
     *
     * @return array{intro:string,local_intro:string,landmarks:string,permit_notes:string}|null
     */
    public function generateAreaContent(\App\Models\AreaServed $area): ?array
    {
        if (empty($this->apiKey)) {
            $this->lastError = 'Gemini API key not configured';
            return null;
        }

        $city = $area->city;

        $prompt = <<<PROMPT
You are an SEO copywriter for GS Construction, a family-owned kitchen, bathroom, and
whole-home remodeling contractor based in Arlington Heights, Illinois. We serve homeowners
across the Chicago suburbs. Founded 2015 by Gregory and Patryk (father & son), 40+ years
combined experience, 5-star rated, English & Polish spoken.

Write unique, factual, local SEO content for our service-area page targeting the city of
**{$city}, Illinois** (Chicago suburb). The goal is to differentiate this page from our
other 88 city pages so Google does not treat it as a duplicate template.

Return ONLY a valid JSON object with EXACTLY these four string keys:

- "intro": 2–3 sentences (180–280 characters). A natural opening for the page that mentions
  the city by name, references the township or surrounding area, and positions GS Construction
  as a local remodeling contractor for {$city} homeowners. No marketing fluff, no "welcome to".

- "local_intro": 3–5 sentences (450–650 characters). Why we're a great fit for THIS city
  specifically. Mention common housing characteristics (e.g. brick bungalows, mid-century ranches,
  newer construction, historic homes — whichever is actually typical for {$city}). Reference
  that we work on kitchens, bathrooms, basements, and whole-home remodels. If you know typical
  home age, lot patterns, or notable subdivisions, mention them. Do NOT invent fake project names.

- "landmarks": A single comma-separated list (no sentences) of 5–8 well-known, real landmarks,
  neighborhoods, school districts, parks, or major streets in {$city}. Use only items you are
  confident exist. Examples format: "Arlington Park, Lake Arlington, District 25 schools,
  Northwest Highway, Recreation Park".

- "permit_notes": 2 sentences (180–260 characters). Generic-but-true statement that structural,
  electrical, and plumbing work in {$city} requires permits from the local building department,
  and that GS Construction handles permit applications and inspection scheduling for the
  homeowner. Mention the township/village if you know it; otherwise say "{$city} Village
  building department" or "{$city} building department".

Hard rules:
- Use plain text only. No markdown, no emoji, no quotes around the JSON values.
- Do NOT invent specific permit codes, fees, or ordinance numbers.
- Do NOT mention competitors.
- Do NOT use the phrases "nestled in", "premier", "your trusted", "look no further".
- Each city's content MUST differ in concrete facts (landmarks, home styles), not just synonyms.
- Return ONLY the JSON object. No code fences, no preamble.
PROMPT;

        $raw = $this->callGeminiMultiImage($prompt, [], 900);
        if ($raw === null) {
            return null;
        }

        // Strip code fences if any.
        $raw = preg_replace('/^```json\s*/i', '', $raw);
        $raw = preg_replace('/^```\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/i', '', $raw);
        $decoded = json_decode(trim($raw), true);

        if (! is_array($decoded)) {
            $this->lastError = 'Failed to parse area-content JSON: ' . $raw;
            return null;
        }

        $required = ['intro', 'local_intro', 'landmarks', 'permit_notes'];
        $out = [];
        foreach ($required as $key) {
            if (empty($decoded[$key]) || ! is_string($decoded[$key])) {
                $this->lastError = "Missing '{$key}' in area-content response: " . $raw;
                return null;
            }
            $out[$key] = trim($decoded[$key]);
        }

        return $out;
    }

    /**
     * Generate unique ZIP landing content for /service-area/{zip} pages.
     *
     * @return array{intro:string,local_context:string,landmarks:string,permit_notes:string}|null
     */
    public function generateZipContent(string $zip, string $city, ?string $areaSlug = null): ?array
    {
        if (empty($this->apiKey)) {
            $this->lastError = 'Gemini API key not configured';
            return null;
        }

        $areaHint = $areaSlug ? "Area slug hint: {$areaSlug}" : 'Area slug hint: unknown';

        $prompt = <<<PROMPT
You are an SEO copywriter for GS Construction, a family-owned kitchen, bathroom,
and whole-home remodeling contractor serving Chicago suburbs.

Create unique content for this ZIP landing page:
- City: {$city}, Illinois
- ZIP: {$zip}
- {$areaHint}

Return ONLY valid JSON with EXACTLY these string keys:
- "intro": 2-3 sentences, 180-280 chars, include city and ZIP naturally.
- "local_context": 3-5 sentences, 420-700 chars, describe housing patterns,
  neighborhood feel, and why remodeling demand exists in this ZIP.
- "landmarks": comma-separated list of 4-8 real nearby landmarks, schools,
  parks, or major roads relevant to this ZIP/city area.
- "permit_notes": 2 sentences, 170-280 chars. State permits are required for
  electrical/plumbing/structural work and GS Construction handles paperwork.

Rules:
- Factual and conservative. Do not invent fake project names.
- No markdown, no code fences, no emojis.
- Do not mention competitors.
- Keep each ZIP's content distinct from others.
PROMPT;

        $raw = $this->callGeminiMultiImage($prompt, [], 900);
        if ($raw === null) {
            return null;
        }

        $raw = preg_replace('/^```json\s*/i', '', $raw);
        $raw = preg_replace('/^```\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/i', '', $raw);
        $decoded = json_decode(trim($raw), true);

        if (! is_array($decoded)) {
            $this->lastError = 'Failed to parse zip-content JSON: ' . $raw;
            return null;
        }

        $required = ['intro', 'local_context', 'landmarks', 'permit_notes'];
        $out = [];
        foreach ($required as $key) {
            if (empty($decoded[$key]) || ! is_string($decoded[$key])) {
                $this->lastError = "Missing '{$key}' in zip-content response: " . $raw;
                return null;
            }
            $out[$key] = trim($decoded[$key]);
        }

        return $out;
    }

    /**
        * Choose a real published Chicagoland project image for a fallback service page hero.
     *
        * Gemini reviews a random sample of published project cover images and returns the
        * best match for the requested service type. The result is cached briefly so the
        * model is not called on every request while still rotating through images.
     */
    public function chooseServiceFallbackImageUrl(string $projectType): ?string
    {
        if (empty($this->apiKey)) {
            $this->lastError = 'Gemini API key not configured';
            return null;
        }

        $projectType = strtolower(trim($projectType));
        $cacheKey = "gemini:service-fallback-image:{$projectType}:" . now()->format('Y-m-d-H');

        return Cache::remember($cacheKey, now()->addDay(), function () use ($projectType) {
            $candidates = ProjectImage::query()
                ->with('project')
                ->where('is_cover', true)
                ->whereHas('project', fn ($query) => $query->published())
                ->inRandomOrder()
                ->orderByDesc('id')
                ->limit(12)
                ->get();

            if ($candidates->isEmpty()) {
                return null;
            }

            $serviceLabel = match ($projectType) {
                'basement' => 'basement remodeling',
                'addition' => 'home additions',
                default => str_replace('-', ' ', $projectType),
            };

            $candidateSummary = $candidates->map(function (ProjectImage $image, int $index): string {
                $project = $image->project;

                return implode(' | ', array_filter([
                    'option ' . ($index + 1),
                    'image_id=' . $image->id,
                    'type=' . ($project?->project_type ?? 'unknown'),
                    'title=' . ($project?->title ?? 'unknown'),
                    'location=' . ($project?->location ?? 'unknown'),
                    'seo_alt=' . trim((string) ($image->seo_alt_text ?? '')),
                    'caption=' . trim((string) ($image->caption ?? '')),
                ]));
            })->implode("\n");

            $prompt = <<<PROMPT
You are selecting a real project photo for a Chicago remodeling website.
Choose the single best image for the {$serviceLabel} service page.

Prefer a published project photo that feels like a real Chicagoland remodeling job.
If multiple options fit, choose the one that best matches the service type and looks most polished.

Candidates:
{$candidateSummary}

Return ONLY valid JSON with exactly these keys:
- "image_id": the chosen image_id as a number
- "reason": a short explanation
PROMPT;

            $raw = $this->callGeminiMultiImage($prompt, [], 200);
            if ($raw === null) {
                return $candidates->first()?->url;
            }

            $raw = preg_replace('/^```json\s*/i', '', $raw);
            $raw = preg_replace('/^```\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/i', '', $raw);
            $decoded = json_decode(trim($raw), true);

            if (! is_array($decoded) || empty($decoded['image_id'])) {
                return $candidates->first()?->url;
            }

            $selected = $candidates->firstWhere('id', (int) $decoded['image_id']);

            return $selected?->url ?: $candidates->first()?->url;
        });
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
