<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Machine-readable Q&A feed for AI engines (ChatGPT, Perplexity, Google AI
 * Overviews, Claude). Served at /geo/answers.json and linked from llms.txt.
 *
 * Source: config/geo-answers.php (curated, short-form answers ready for
 * direct citation by generative search systems).
 */
class GeoAnswersController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $payload = Cache::remember('geo_answers_v1', 3600, function (): array {
            $cfg = (array) config('geo-answers');
            $meta = (array) ($cfg['meta'] ?? []);
            $answers = (array) ($cfg['answers'] ?? []);

            return [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'name' => ($meta['business'] ?? 'Business') . ' — Q&A for AI engines',
                'url' => url('/geo/answers.json'),
                'dateModified' => now()->toIso8601String(),
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $meta['business'] ?? 'GS Construction',
                    'url' => url('/'),
                    'telephone' => $meta['phone'] ?? null,
                    'email' => $meta['email'] ?? null,
                    'areaServed' => $meta['service_area'] ?? null,
                    'knowsLanguage' => $meta['languages'] ?? ['English'],
                ],
                'mainEntity' => array_map(fn (array $a) => [
                    '@type' => 'Question',
                    'name' => $a['q'] ?? '',
                    'topics' => $a['topics'] ?? [],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $a['a'] ?? '',
                    ],
                ], $answers),
            ];
        });

        return response()->json($payload, 200, [
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'all',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
