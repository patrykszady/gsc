<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TestimonialProjectTypeClassifier
{
    /**
     * @param  array<int, string>  $allowedTypes
     */
    public function classify(string $reviewText, array $allowedTypes, ?string $model = null): ?string
    {
        $reviewText = trim($reviewText);

        if ($reviewText === '') {
            return null;
        }

        $apiKey = config('services.openai.api_key');
        $model = $model ?: config('services.openai.model');

        if (is_string($apiKey) && $apiKey !== '') {
            $result = $this->classifyWithOpenAi($reviewText, $allowedTypes, $apiKey, $model);

            if ($result !== null) {
                return $result;
            }
        }

        return $this->classifyHeuristically($reviewText, $allowedTypes);
    }

    /**
     * @param  array<int, string>  $allowedTypes
     */
    protected function classifyWithOpenAi(string $reviewText, array $allowedTypes, string $apiKey, string $model): ?string
    {
        $allowed = implode(', ', $allowedTypes);

        $system = <<<TEXT
You are a strict text classifier for home remodeling reviews.
Choose exactly ONE project_type from this allowed list:
{$allowed}

Rules:
- Return ONLY a JSON object like: {"project_type":"kitchen"}
- If unclear, return: {"project_type":null}
TEXT;

        $user = <<<TEXT
Review:
"""
{$reviewText}
"""
TEXT;

        try {
            $response = Http::timeout(30)
                ->withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'max_tokens' => 50,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        $decoded = $this->safeJsonDecode($content);

        if (! is_array($decoded)) {
            return null;
        }

        $projectType = $decoded['project_type'] ?? null;

        if ($projectType === null) {
            return null;
        }

        if (! is_string($projectType)) {
            return null;
        }

        $projectType = trim(strtolower($projectType));

        return in_array($projectType, $allowedTypes, true) ? $projectType : null;
    }

    protected function safeJsonDecode(string $content): mixed
    {
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Attempt to extract the first JSON object from the response.
        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $maybeJson = substr($content, $start, $end - $start + 1);

        $decoded = json_decode($maybeJson, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * @param  array<int, string>  $allowedTypes
     */
    protected function classifyHeuristically(string $reviewText, array $allowedTypes): ?string
    {
        $t = strtolower($reviewText);

        $contains = static fn (array $needles) => collect($needles)->contains(fn ($n) => str_contains($t, $n));

        $candidates = [];

        if ($contains(['kitchen', 'cabinet', 'countertop', 'island', 'backsplash', 'pantry'])) {
            $candidates[] = 'kitchen';
        }

        if ($contains(['bathroom', 'shower', 'tub', 'vanity', 'tile', 'heated floors'])) {
            $candidates[] = 'bathroom';
        }

        if ($contains(['basement', 'drywall in the basement', 'lower level', 'dry bar'])) {
            $candidates[] = 'basement';
        }

        if ($contains(['addition', 'add-on', 'second story', 'bump out'])) {
            $candidates[] = 'addition';
        }

        if ($contains(['whole home', 'whole-house', 'whole house', 'full house', 'entire house', 'first floor remodel', 'gut', 'renovation throughout', 'hardwood floors throughout'])) {
            $candidates[] = 'home-remodel';
        }

        if ($contains(['mudroom', 'laundry room', 'laundry rooms', 'laundry'])) {
            $candidates[] = 'mudroom';
        }

        if ($contains(['siding', 'exterior', 'roof', 'windows', 'doors', 'porch'])) {
            $candidates[] = 'exterior';
        }

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $allowedTypes, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
