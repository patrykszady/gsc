<?php

namespace App\Services\Seo;

use App\Models\AreaServed;
use App\Services\AiContentService;
use App\Support\Citations\ListingPayload;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Schema;

/**
 * Three candidate "From the business" descriptions for the Google Business
 * Profile — keyword-led, conversion-led and trust-led — written from the
 * canonical listing payload, the core towns and the phrases people actually
 * search. Google allows 750 characters, no URLs, no phone numbers, and
 * rejects promotional pricing, so the prompt forbids all of that.
 */
class GbpDescriptionWriter
{
    public const LIMIT = 750;

    public function __construct(protected AiContentService $ai) {}

    /**
     * @return array{keyword: string, conversion: string, trust: string}|null
     */
    public function variants(?string $current = null): ?array
    {
        $listing = ListingPayload::make();
        $towns = AreaServed::coreTowns(6);
        // Researched phrases, minus navigational ones and anything carrying a competitor's name.
        $phrases = [];
        if (Schema::hasTable('seo_keywords')) {
            $competitors = Schema::hasTable('map_pack_competitors')
                ? Tenancy::table('map_pack_competitors')->whereNotNull('name')->pluck('name')->map(fn ($n) => mb_strtolower(trim((string) preg_replace('/\s*&.*$/', '', (string) $n))))->filter()->unique()->all()
                : [];
            $phrases = Tenancy::table('seo_keywords')->where('opportunity', '>', 0)->whereNotNull('service')
                ->where(fn ($q) => $q->whereNull('intent')->orWhere('intent', '!=', 'navigational'))
                ->orderByDesc('opportunity')->limit(20)->pluck('keyword')->map(fn ($k) => (string) $k)
                ->reject(fn ($k) => collect($competitors)->contains(fn ($c) => $c !== '' && str_contains(mb_strtolower($k), $c)))
                ->take(8)->values()->all();
        }
        $prompt = implode("\n", [
            'Write three descriptions for the "From the business" field of a Google Business Profile.',
            'Hard rules: each description at most ' . (self::LIMIT - 50) . ' characters, plain text, no URLs, no phone numbers, no email, no prices, no ALL CAPS, no exclamation marks, no claims that are not in the facts below. Written in first person plural. American English.',
            'Return ONLY a JSON object with keys "keyword", "conversion", "trust" and string values — no markdown, no commentary.',
            '',
            'Facts about the business:',
            '- Name: ' . $listing['name'],
            '- Based in ' . $listing['address']['city'] . ', ' . $listing['address']['state'] . '; serving ' . implode(', ', $towns) . ' and the surrounding suburbs',
            '- Founded ' . $listing['founded'] . '; owners: ' . $listing['contact']['owners'],
            '- Services: ' . implode(', ', $listing['services']),
            '- ' . ($listing['stats']['reviews'] ? $listing['stats']['reviews'] . ' verified five-star reviews' : 'Five-star rated'),
            '- Free in-home estimates; licensed, insured and bonded; written itemized estimates',
            '- Languages: ' . implode(' and ', $listing['languages']),
            $phrases ? '- Phrases customers search for: ' . implode('; ', $phrases) : '',
            $current ? "\nCurrent description (improve on it, do not repeat it):\n" . $current : '',
            '',
            'Variant "keyword": works the searched phrases and the town names in naturally, service-first.',
            'Variant "conversion": leads with what a homeowner gets (free estimate, itemized pricing, owners on site) and ends with a soft call to action.',
            'Variant "trust": leads with the family story, years in business, reviews and licensing.',
        ]);
        $raw = $this->ai->generateText($prompt, 1500, 0.6);
        if ($raw === null) {
            return null;
        }
        $json = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($raw)) ?? $raw);
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }
        $out = [];
        foreach (['keyword', 'conversion', 'trust'] as $k) {
            $text = trim(preg_replace('/\s+/', ' ', (string) ($data[$k] ?? '')) ?? '');
            $text = preg_replace('#https?://\S+|www\.\S+|\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}#', '', $text) ?? $text;
            $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
            if (mb_strlen($text) < 120) {
                return null;
            }
            $out[$k] = mb_substr($text, 0, self::LIMIT);
        }

        return $out;
    }
}
