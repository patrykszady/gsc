<?php

namespace App\Services\Seo;

use App\Models\AreaServed;
use App\Services\Blog\PartnerSiteFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reads a map-pack competitor's homepage for ANALYSIS: title, description,
 * a text excerpt, the headings, and which of our towns and services they
 * name. Nothing fetched here is ever rendered or reused as our copy, and no
 * images are touched — this is competitive intelligence, not content.
 */
class CompetitorSiteFetcher
{
    public const SERVICES = [
        'kitchen' => 'Kitchen', 'bathroom' => 'Bathroom', 'basement' => 'Basement', 'addition' => 'Additions',
        'whole home' => 'Whole-home', 'home remodel' => 'Home remodel', 'roof' => 'Roofing', 'deck' => 'Decks',
        'siding' => 'Siding', 'window' => 'Windows', 'flooring' => 'Flooring', 'tile' => 'Tile', 'cabinet' => 'Cabinetry',
        'countertop' => 'Countertops', 'design' => 'Design', 'shower' => 'Showers', 'tub' => 'Tubs', 'painting' => 'Painting',
    ];

    public function __construct(protected PartnerSiteFetcher $partner) {}

    /** @return array<string, mixed>|null attributes for local_falcon_competitors, or null when unreachable */
    public function read(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        try {
            $response = Http::timeout(12)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; GSConstructionSeoBot/1.0; +' . url('/') . ')'])
                ->get($url);
        } catch (\Throwable $e) {
            Log::info('Competitor site unreachable', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $html = (string) $response->body();
        $parsed = $this->partner->parse($html);

        preg_match_all('#<h([1-3])[^>]*>(.*?)</h\1>#is', $html, $m);
        $headings = collect($m[2])
            ->map(fn ($h) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''))
            ->filter(fn ($h) => $h !== '' && mb_strlen($h) <= 120)
            ->unique()
            ->take(30)
            ->values()
            ->all();

        $text = ' ' . mb_strtolower(implode(' ', array_merge($headings, [(string) $parsed['site_title'], (string) $parsed['site_description'], (string) $parsed['site_excerpt']]))) . ' ';

        $towns = AreaServed::query()->pluck('city')->map(fn ($c) => trim((string) $c))->filter()
            ->filter(fn ($c) => str_contains($text, ' ' . mb_strtolower($c) . ' ') || str_contains($text, ' ' . mb_strtolower($c) . ','))
            ->values()->all();

        $services = collect(self::SERVICES)->filter(fn ($label, $word) => str_contains($text, $word))->values()->all();

        return [
            'site_title' => $parsed['site_title'],
            'site_description' => $parsed['site_description'],
            'site_excerpt' => $parsed['site_excerpt'] ? Str::limit($parsed['site_excerpt'], 1200, '') : null,
            'site_headings' => $headings,
            'site_towns' => $towns,
            'site_services' => $services,
            'site_fetched_at' => now(),
        ];
    }
}
