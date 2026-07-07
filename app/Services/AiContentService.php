<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AiContentService
{
    protected string $apiKey;
    protected string $model;
    protected ?string $lastError = null;

    public function __construct()
    {
        $this->apiKey = config('services.google.gemini_api_key', '');
        $this->model = config('services.google.gemini_model', 'gemini-2.5-flash');
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
You are an SEO and GEO (generative-engine optimization) copywriter for GS Construction,
a licensed and insured family-owned home remodeling company serving the Chicago suburbs since 2015.
You are looking at {$allImages->count()} photos from this project. Study ALL the images carefully.

Write a detailed project description of 100-150 words (about 5-7 sentences) that:
- Describes the type of work done ({$projectType}) in {$location}
- References specific materials, fixtures, finishes, and design features visible across ALL the project photos
- Includes concrete specifics — counts, dimensions, or measurements ONLY when they are clearly visible in the photos or are safe, generic facts (e.g. typical 4-10 week timelines, licensed since 2015). Never invent precise figures you cannot see.
- Write all counts, quantities, and measurements as digit numerals (e.g. "3 pendant lights", "2 vanities", "since 2015"), never as spelled-out words, so the copy is dense with concrete figures
- Weaves in at least 2-3 specific factual details per sentence so the copy reads densely informative, not vague
- Highlights quality craftsmanship and names "GS Construction" at least once
- Is SEO-optimized with natural local-remodeling keywords
- Sounds professional but approachable

Project title: {$project->title}{$imageContext}

Return ONLY the description text (plain prose, no headings, no JSON, no markdown).
PROMPT;

        return $this->callGeminiMultiImage($prompt, $multiImageData, maxOutputTokens: 800);
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
    protected function callGeminiMultiImage(string $prompt, array $imagesData = [], int $maxOutputTokens = 500, float $temperature = 0.7): ?string
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

        // Disable Gemini 2.5 "thinking" budget — reasoning tokens otherwise
        // eat into maxOutputTokens and truncate JSON responses mid-string.
        // Safe no-op on non-2.5 models (field is ignored).
        $generationConfig = [
            'temperature' => $temperature,
            'maxOutputTokens' => $maxOutputTokens,
            'thinkingConfig' => ['thinkingBudget' => 0],
        ];

        try {
            $response = Http::timeout(120)
                ->acceptJson()
                ->post($url, [
                    'contents' => [
                        [
                            'parts' => $parts,
                        ],
                    ],
                    'generationConfig' => $generationConfig,
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

        // v19 = SEO-dense prompt. Target 120-140 chars, two city mentions,
        // room-noun + service-variant keyword pairing without the v16
        // self-conflict (project_type is normalized to a clean room noun so
        // "home-remodel" doesn't produce "home remodel remodel").
        $cacheKey = "yelp_caption_seo:v22:{$image->id}:{$limit}:" . md5($original);
        return Cache::remember($cacheKey, now()->addDays(30), function () use ($image, $project, $original, $limit) {
            // Normalize project_type → a clean ROOM/SCOPE NOUN (no "remodel"/
            // "renovation" suffix) so the prompt can freely pair it with
            // service-variant words.
            $rawType = (string) ($project?->project_type ?? '');
            $roomNoun = strtolower(str_replace(['-', '_'], ' ', $rawType));
            $roomNoun = trim(preg_replace('/\b(remodel(?:s|ing|ed)?|renovat(?:e|es|ed|ing|ion))\b/i', '', $roomNoun) ?? $roomNoun);
            $roomNoun = preg_replace('/\s+/', ' ', $roomNoun) ?? $roomNoun;
            $roomNoun = $roomNoun !== '' ? $roomNoun : 'home';
            // Display variant for examples (e.g. "kitchen", "bathroom", "home").

            // City only — strip any ", IL" / state code so we don't burn chars.
            $rawLocation = trim((string) ($project?->location ?? ''));
            $rawLocation = preg_replace('/\s*,\s*[A-Z]{2}\b.*$/', '', $rawLocation) ?? $rawLocation;
            $location = $rawLocation !== '' ? $rawLocation : 'Chicago suburbs';

            // Target near the cap — every character is SEO real estate on Yelp.
            $targetMin = max(120, $limit - 20);

            $prompt = <<<PROMPT
Write ONE Yelp business-photo caption for GS Construction (home remodeling contractor in {$location}).

PRIMARY GOAL: USE ALMOST ALL {$limit} CHARACTERS for SEO. Aim for {$targetMin}-{$limit} chars. Captions shorter than {$targetMin} waste Yelp's meta space.

REQUIRED KEYWORDS (work them in naturally as part of normal sentences — do NOT make a comma-separated tag list):
- "{$location}" — twice if it fits, once minimum.
- "GS Construction" — once.
- "{$roomNoun}" paired with TWO different service words from this list: remodel, renovation, remodeling, contractor, design-build. Example pairings: "{$roomNoun} remodel" + "{$roomNoun} renovation", or "{$roomNoun} remodeling contractor" + "{$roomNoun} renovation".
- A concrete scope detail from the SOURCE CAPTION (the room/area, the work, or the phase — e.g. "double vanity", "island", "freestanding tub", "opened floor plan", "demo to punch-list").

GRAMMAR:
- 1 OR 2 complete sentences. Each sentence has a subject + verb and ends with a period.
- Final character must be ".".
- No hashtags, emojis, line breaks, quotes, colons, em-dashes, or exclamation points.
- No comma-separated keyword lists ("kitchen remodel, kitchen renovation, design-build contractor" is BANNED — that's tag stuffing, not writing).

CONTENT BANS:
- No materials, colors, finishes, fixtures, brand names ("white", "quartz", "marble", "tile", "shaker", "hardwood", "stainless", "gold", "cabinets", "countertops", etc.).
- No homeowners, owners, clients, families.
- No filler adjectives: stunning, beautiful, modern, gorgeous, sleek, elegant, luxurious, perfect, dream, breathtaking.
- No vague status verbs: "wrapping up", "working on", "currently", "in the middle of", "in progress".

GOOD EXAMPLES (120-140 chars, SEO-dense, ends with "."):
- "GS Construction completed this {$location} {$roomNoun} remodel with a reconfigured layout, a {$location} {$roomNoun} renovation handled end to end."
- "This {$location} {$roomNoun} remodel by GS Construction opened the floor plan, a {$location} {$roomNoun} remodeling contractor project we ran top to bottom."
- "GS Construction reconfigured this {$location} {$roomNoun} remodel, a design-build {$roomNoun} renovation our team delivered in {$location} from demo to punch-list."

SOURCE CAPTION (use its scope details; drop materials, colors, and filler):
{$original}

Output ONLY the final caption text on one line. No labels, no quotes, no preamble.
PROMPT;

            $maxAttempts = 4;
            $lastClean = '';
            $lastReason = '';
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $attemptPrompt = $prompt;
                if ($attempt > 1 && $lastReason !== '') {
                    $attemptPrompt = "Your previous output was rejected: {$lastReason}\nPrevious: \"{$lastClean}\"\n\nTry again, simpler and shorter.\n\n" . $prompt;
                }

                // Slightly creative — 0.45 gives us varied, informative output
                // without the constraint-violation risk of full 0.7.
                $raw = $this->callGeminiMultiImage($attemptPrompt, [], 300, 0.45);
                if (!is_string($raw) || trim($raw) === '') {
                    $lastReason = 'Empty response.';
                    continue;
                }
                $clean = trim($raw);
                $clean = trim($clean, "\"'`\n\r\t ");
                $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

                // Smart truncation — cut to last complete sentence within the cap.
                if (mb_strlen($clean) > $limit) {
                    $clean = $this->truncateToCompleteSentence($clean, $limit);
                }

                // SEO PADDING: if the cleaned caption is well under the cap,
                // append a deterministic SEO clause to fill remaining budget.
                $clean = $this->padCaptionForSeo($clean, $location, $roomNoun, $limit);

                $lastClean = $clean;

                $reason = $this->captionRejectReason($clean, $location, $roomNoun, $limit);
                if ($reason === null) {
                    return $clean !== '' ? $clean : null;
                }
                $lastReason = $reason;

                Log::channel('yelp')->warning('Yelp caption rewrite failed quality gate; retrying', [
                    'image_id' => $image->id,
                    'attempt' => $attempt,
                    'reason' => $reason,
                    'caption' => $clean,
                ]);
            }

            // Gemini still failed. Return null so the caller can fall back to
            // a deterministic, guaranteed-valid caption. We do NOT throw — the
            // upload should never block on caption generation.
            Log::channel('yelp')->warning('Yelp caption rewrite gave up after retries; caller will use deterministic fallback', [
                'image_id' => $image->id,
                'last_reason' => $lastReason,
                'last_output' => $lastClean,
            ]);
            return null;
        });
    }

    /**
     * Validate a Gemini-rewritten caption. Returns false if it contains a
     * trailing noun-phrase fragment, duplicate clauses, or other malformations
     * we'd rather not ship to Yelp.
     */
    protected function isCaptionWellFormed(string $caption, string $location, string $type): bool
    {
        return $this->captionRejectReason($caption, $location, $type, 140) === null;
    }

    /**
     * Returns null if the caption passes the basic quality gate. Otherwise
     * returns a SHORT, SPECIFIC reason. Kept intentionally minimal — we only
     * reject things that would actually look bad on Yelp (empty, too long,
     * truncated mid-sentence). Stylistic enforcement lives in the prompt.
     */
    protected function captionRejectReason(string $caption, string $location, string $type, int $limit = 140): ?string
    {
        if ($caption === '') {
            return 'Caption was empty.';
        }

        if (mb_strlen($caption) > $limit) {
            return sprintf(
                'Caption was %d characters; max is %d. Write a SHORTER caption.',
                mb_strlen($caption),
                $limit
            );
        }

        // Reject only severely under-utilized output. Aspirational target is
        // 110-140 (set in the prompt), but a tidy 85-char caption that names
        // the city + business + concrete detail is still better than retrying
        // forever — Yelp doesn't penalize shorter captions, only missing ones.
        $minChars = 80;
        if (mb_strlen($caption) < $minChars) {
            return sprintf(
                'Caption was only %d characters. Aim for 110-%d. Use almost all available characters \u2014 add the second "%s" mention, a service-variant word (remodel/renovation/remodeling contractor), or a concrete scope detail from the source.',
                mb_strlen($caption),
                $limit,
                $location
            );
        }

        // Must end with terminal punctuation.
        if (! preg_match('/[.!?]$/', $caption)) {
            return 'Caption did not end with a period. Every caption must end with ".".';
        }

        return null;
    }

    /**
     * Trim a too-long caption back inside $limit. First tries the last
     * complete sentence; if no full sentence fits (single-sentence overage),
     * cuts at the last clause boundary (", " / " and " / " with " / " as ")
     * and re-terminates with a period. Returns the original on no-op.
     */
    protected function truncateToCompleteSentence(string $caption, int $limit): string
    {
        if (mb_strlen($caption) <= $limit) {
            return $caption;
        }

        $head = mb_substr($caption, 0, $limit);

        // (1) Prefer last full sentence within the limit.
        if (preg_match_all('/[.!?](?=\s|$)/u', $head, $m, PREG_OFFSET_CAPTURE)) {
            $last = end($m[0]);
            $end = $last[1] + mb_strlen($last[0]);
            $candidate = rtrim(substr($head, 0, $end));
            if ($candidate !== '' && mb_strlen($candidate) >= 60) {
                return $candidate;
            }
        }

        // (2) Single-sentence overage: cut at last clause boundary inside
        // the limit and add a period. Leaves a clean partial sentence
        // instead of a mid-clause fragment.
        $boundaries = [];
        foreach ([', ', '; ', ' and ', ' with ', ' as ', ' including ', ' featuring '] as $needle) {
            $pos = mb_strrpos($head, $needle);
            if ($pos !== false) {
                $boundaries[] = $pos;
            }
        }
        if (!empty($boundaries)) {
            $cut = max($boundaries);
            // Require we keep at least 60 chars so we don't lop off the whole caption.
            if ($cut >= 60) {
                $candidate = rtrim(mb_substr($head, 0, $cut), " ,;:-") . '.';
                if (mb_strlen($candidate) <= $limit) {
                    return $candidate;
                }
            }
        }

        // (3) Last resort: cut at the last space and add a period.
        $cut = mb_strrpos($head, ' ');
        if ($cut !== false && $cut >= 60) {
            return rtrim(mb_substr($head, 0, $cut), " ,;:-") . '.';
        }

        return $caption;
    }

    /**
     * Append a deterministic SEO clause to a short caption to use available
     * meta budget. Picks the longest tag that fits and doesn't duplicate
     * content already in the caption. Returns input unchanged if no clean
     * tag fits or the caption is already near the cap.
     */
    protected function padCaptionForSeo(string $caption, string $location, string $roomNoun, int $limit): string
    {
        $caption = rtrim($caption);
        if ($caption === '' || mb_strlen($caption) >= $limit - 10) {
            return $caption;
        }
        // Ensure caption ends with terminal punctuation before we append.
        if (! preg_match('/[.!?]$/', $caption)) {
            return $caption;
        }

        $lc = mb_strtolower($caption);
        $room = trim($roomNoun) !== '' ? $roomNoun : 'home';

        // Candidate tags, longest first. Each is a complete sentence.
        $candidates = [
            "Trusted {$location} {$room} remodeling contractor and design-build team.",
            "Your local {$location} {$room} remodeling contractor and design-build team.",
            "Full-service {$location} {$room} remodeling contractor.",
            "{$location} {$room} renovation by GS Construction.",
            "Local {$location} {$room} remodeling contractor.",
            "{$location} {$room} remodeling contractor.",
            "Full-service {$location} {$room} renovation.",
            "{$location} {$room} renovation experts.",
        ];

        foreach ($candidates as $tag) {
            $tagLc = mb_strtolower($tag);
            // Skip tags whose core 3-word phrase already appears in the caption.
            $coreWords = preg_split('/\s+/u', preg_replace('/[^\w\s]/u', '', $tagLc) ?? '') ?: [];
            $coreNgram = implode(' ', array_slice($coreWords, 0, 3));
            if ($coreNgram !== '' && mb_strpos($lc, $coreNgram) !== false) {
                continue;
            }
            $candidate = $caption . ' ' . $tag;
            if (mb_strlen($candidate) <= $limit) {
                return $candidate;
            }
        }

        return $caption;
    }

    /**
     * Get image data as base64 for Gemini API.
     */
    protected function getImageData(ProjectImage $image): ?array
    {
        $disk = 'public';
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
        $cityOnly = trim(explode(',', (string) $project->location)[0]) ?: 'Chicago';

        // Randomise the opening angle so captions don't all start the same way
        // (Gemini defaults to "Step into..." over and over). A fresh angle per
        // call + higher temperature keeps each post distinct.
        $openingAngles = [
            'lead with the single most striking visual detail you can see (a material, colour, texture, or fixture)',
            'open with the feeling or mood the finished space creates',
            'start with a short, punchy question that pulls the reader in',
            'begin by naming the room and the city, then the standout design choice',
            'lead with the transformation — what this space became',
            'open on a craftsmanship angle: the details that set this work apart',
            'start with the everyday lifestyle benefit for the homeowner',
            'lead with a confident one-line statement about the result',
            'open with a specific number or dimension if one is visually implied (island size, ceiling height, etc.)',
            'start mid-scene, describing the light or the first thing the eye lands on',
        ];
        $angle = $openingAngles[array_rand($openingAngles)];

        $prompt = <<<PROMPT
You are a social media manager for GS Construction, a premium home remodeling company based in Chicago.
Analyze this project photo and generate an engaging Instagram/Facebook post.

Project Context:
{$projectContext}

Existing AI description of this image: {$image->caption}

Generate a JSON object with these keys:
1. "caption": An engaging social media caption (2-4 sentences). Requirements:
   - Opening angle for THIS caption: {$angle}. Vary the first few words every time.
   - Do NOT begin with any of these overused templates: "Step into", "Step inside", "Welcome to", "Transform your", "Imagine", "Discover". Never open two posts the same way.
   - Describe what's shown in the photo with specific details (materials, colors, design choices)
   - Mention the city ({$cityOnly}) naturally — use the CITY NAME ONLY, never the state abbreviation (no ", IL", no "Illinois")
   - Keep it concise — no "link in bio", no "DM us", no CTA sentence. Let the work speak for itself.
   - Sound premium but approachable — like a proud craftsman showing off great work
   - Do NOT include hashtags in the caption
   - Do NOT include any "http", "https", "www.", ".com", or other URL fragments anywhere in the caption.

2. "hashtags": A string of EXACTLY 10–12 relevant hashtags separated by single spaces. Rules:
   - No duplicates (case-insensitive). #GSConstruction and #GSConstruction count as one.
   - Pick a mix that covers ALL of these buckets (don't skip any):
       * Brand: #GSConstruction
       * Location: #{$this->locationToHashtag($project->location)} plus ONE of #ChicagoContractor / #ChicagoRemodeling
       * Room/scope: pick from #KitchenRemodel #BathroomRemodel #BasementRemodel #HomeRemodel #FireplaceRemodel #ReadingNook (whichever fits the photo)
       * Industry: ONE of #GeneralContractor #DesignBuild #HomeRenovation
       * Visible features: 2–4 niche tags describing materials/style ACTUALLY VISIBLE in the photo (e.g. #QuartzCountertops, #StoneFireplace, #CustomCabinetry)
   - AVOID megafeed tags with >50M posts (#interiordesign, #home, #design) — they bury the post.
   - Each hashtag in PascalCase or CamelCase, starts with #, no spaces inside.

Rules:
- Caption must be under 1500 characters
- No markdown formatting in the caption
- Return ONLY valid JSON, no markdown code blocks

PROMPT;

        // Higher temperature widens word choice so openings/phrasing vary.
        $response = $this->callGeminiMultiImage($prompt, [$imageData], 2000, 0.95);

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

        // Tolerant fallback: Gemini occasionally truncates the JSON when it
        // hits maxOutputTokens mid-string. Pull caption/hashtags out with
        // regex so a single truncated post doesn't abort the whole publish.
        if (!is_array($decoded)) {
            $caption = null;
            $hashtags = null;
            if (preg_match('/"caption"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $content, $m)) {
                $caption = stripcslashes($m[1]);
            } elseif (preg_match('/"caption"\s*:\s*"([^"]*)$/s', $content, $m)) {
                $caption = stripcslashes($m[1]);
            }
            if (preg_match('/"hashtags"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $content, $m)) {
                $hashtags = stripcslashes($m[1]);
            } elseif (preg_match('/"hashtags"\s*:\s*"([^"]*)$/s', $content, $m)) {
                // Drop the dangling (likely partial) last hashtag.
                $hashtags = preg_replace('/\s*#\w*$/', '', stripcslashes($m[1]));
            }
            if ($caption && $hashtags) {
                $decoded = ['caption' => $caption, 'hashtags' => $hashtags];
            }
        }

        if (!is_array($decoded) || empty($decoded['caption']) || empty($decoded['hashtags'])) {
            $this->lastError = 'Failed to parse social media JSON: ' . $content;
            return null;
        }

        $caption = $this->sanitizeSocialCaption((string) $decoded['caption']);
        $hashtags = $this->sanitizeSocialHashtags((string) $decoded['hashtags']);

        return [
            'caption' => mb_substr($caption, 0, 1500),
            'hashtags' => $hashtags,
        ];
    }

    /**
     * Remove URLs/URL fragments from a caption — Instagram does not make
     * caption URLs clickable, and the publisher appends a short link
     * separately. Also collapses any whitespace artefacts left behind.
     */
    protected function sanitizeSocialCaption(string $caption): string
    {
        $caption = trim($caption);
        // Strip full URLs and bare host fragments.
        $caption = preg_replace('#https?://\S+#i', '', $caption) ?? $caption;
        $caption = preg_replace('#\bwww\.\S+#i', '', $caption) ?? $caption;
        $caption = preg_replace('#\b[\w-]+\.(com|net|org|co|io)\b\S*#i', '', $caption) ?? $caption;
        // Remove leftover "See the full project at ." style fragments.
        $caption = preg_replace('/\b(see|view|check out|find)\s+(the\s+)?(full\s+)?project\s+at\s*[\.,!\?]?/i', '', $caption) ?? $caption;
        // Remove "link in bio" / "tap the link" style CTAs (any sentence containing them).
        $caption = preg_replace('/[^.!?\n]*\b(link in (our )?bio|tap the link|link in profile)\b[^.!?\n]*[.!?]?/i', '', $caption) ?? $caption;
        // Drop US state abbreviation after city (", IL" / ", IL.") — caption should use city only.
        $caption = preg_replace('/,\s*[A-Z]{2}\b\.?/', '', $caption) ?? $caption;
        // Collapse double spaces / orphan punctuation.
        $caption = preg_replace('/[ \t]{2,}/', ' ', $caption) ?? $caption;
        $caption = preg_replace('/\s+([\.,!\?])/', '$1', $caption) ?? $caption;
        return trim($caption);
    }

    /**
     * Normalise hashtag string: split, dedupe (case-insensitive), drop
     * megafeed tags, cap at 12, return space-separated.
     */
    protected function sanitizeSocialHashtags(string $raw, int $max = 12): string
    {
        $blocklist = [
            '#interiordesign', '#home', '#design', '#beforeandafter',
            '#homedecor', '#luxuryhome', '#luxuryhomes', '#homedesign',
        ];

        $tokens = preg_split('/\s+/', trim($raw)) ?: [];
        $seen = [];
        $out = [];
        $brand = null;
        $cityTag = null;
        // Detect city tag (locationToHashtag-style: city name with non-alnum stripped).
        $cityNeedle = null; // set later from caller context if needed
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if ($token[0] !== '#') {
                $token = '#' . ltrim($token, '#');
            }
            // Drop anything non-tag-ish.
            if (!preg_match('/^#[A-Za-z0-9_]{2,}$/', $token)) {
                continue;
            }
            $key = strtolower($token);
            if (isset($seen[$key]) || in_array($key, $blocklist, true)) {
                continue;
            }
            $seen[$key] = true;
            // Hold #GSConstruction aside — it goes last.
            if ($key === '#gsconstruction') {
                $brand = $token;
                continue;
            }
            // Hold first location-style tag aside (matches known city patterns or #Chicago*).
            if ($cityTag === null && preg_match('/^#(chicago[a-z]*|palatine|arlingtonheights|prospectheights|mountprospect|barrington|wheeling|buffalogrove|northbrook|glenview|deerfield|elgin|schaumburg|hoffmanestates|inverness|naperville|evanston|skokie|desplaines|parkridge|highlandpark|rollingmeadows|streamwood)$/i', $token)) {
                // Prefer the specific city over #Chicago* when both appear; keep first hit but allow upgrade if it's #ChicagoContractor-ish and a more specific one comes later.
                if (!preg_match('/^#chicago/i', $token)) {
                    $cityTag = $token;
                } elseif ($cityTag === null) {
                    $cityTag = $token;
                }
                continue;
            }
            $out[] = $token;
        }

        // Re-append city then brand at the end.
        if ($cityTag !== null) {
            $out[] = $cityTag;
        }
        if ($brand !== null) {
            $out[] = $brand;
        }

        if (count($out) > $max) {
            // Trim from the middle (keep first niche tags + the brand/city tail).
            $tail = array_slice($out, -2); // city + brand
            $head = array_slice($out, 0, $max - count($tail));
            $out = array_merge($head, $tail);
        }

        return implode(' ', $out);
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
