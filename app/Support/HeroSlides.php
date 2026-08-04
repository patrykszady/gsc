<?php

namespace App\Support;

use App\Models\ProjectImage;

/**
 * Slide data for <x-hero-carousel>, built from curated project covers.
 *
 * The carousel component is deliberately style- and query-free so a tenant with
 * no project photos can hand it placeholders. This is the gsc-shaped source for
 * it: real covers, sized the same way MainProjectHeroSlider sizes them so the
 * two heroes request identical derivatives and share a warm cache rather than
 * each pulling its own rendition of the same photo.
 */
class HeroSlides
{
    /**
     * @return array<int, array{url: string, alt: string, srcset: string}>
     */
    public static function fromProjects(?string $type = null, int $count = 5): array
    {
        return ProjectImage::curatedCovers($type, $count)
            ->map(static fn (ProjectImage $image): ?array => self::slide($image))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Stand-in slides for a tenant that has no photography of its own yet.
     *
     * Inline SVG data URIs rather than stock imagery: a client site may only
     * publish photos that client supplied (docs/legal), and these are visibly
     * placeholders instead of something a passer-by could mistake for finished
     * work. Self-contained too — no external host, no licensing, nothing to
     * clean up later beyond deleting the call.
     *
     * @param  array<int, string>  $labels
     * @return array<int, array{url: string, alt: string}>
     */
    public static function placeholders(array $labels): array
    {
        // Warm neutrals that sit with a stone/ink theme without pretending to be
        // a photograph. Distinct tones so it is obvious the carousel advanced.
        $tones = [['#e7e5e4', '#d6d3d1'], ['#e5e1dc', '#cfc9c2'], ['#eae6e1', '#d3ccc4']];

        $slides = [];
        foreach (array_values($labels) as $i => $label) {
            [$from, $to] = $tones[$i % count($tones)];

            $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 900" role="img" aria-label="{$label}">
              <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="{$from}"/><stop offset="100%" stop-color="{$to}"/>
              </linearGradient></defs>
              <rect width="1600" height="900" fill="url(#g)"/>
              <text x="800" y="460" text-anchor="middle" font-family="Georgia, serif"
                    font-size="46" fill="#78716c">{$label}</text>
            </svg>
            SVG;

            $slides[] = [
                'url' => 'data:image/svg+xml;charset=utf-8,' . rawurlencode(preg_replace('/\s+/', ' ', trim($svg))),
                'alt' => '',   // decorative placeholder: naming it would assert content that isn't there
            ];
        }

        return $slides;
    }

    /**
     * @return array{url: string, alt: string, srcset: string}|null
     */
    private static function slide(ProjectImage $image): ?array
    {
        $large = $image->getWebpThumbnailUrl('large') ?? $image->getThumbnailUrl('large') ?? $image->url;
        $hero = $image->getWebpThumbnailUrl('hero') ?? $image->getThumbnailUrl('hero');
        $medium = $image->getWebpThumbnailUrl('medium') ?? $image->getThumbnailUrl('medium');
        $small = $image->getWebpThumbnailUrl('small') ?? $image->getThumbnailUrl('small');

        // hero (1200px) over large (2400px) as the default src: on the phones
        // most of this traffic arrives on, large is four times the pixels the
        // viewport can use.
        $url = $hero ?? $large;
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return [
            'url' => $url,
            'alt' => (string) ($image->seo_alt_text ?: $image->alt_text ?: $image->project?->title ?: ''),
            'srcset' => implode(', ', array_filter([
                $small ? "{$small} 300w" : null,
                $medium ? "{$medium} 600w" : null,
                $hero ? "{$hero} 1200w" : null,
                $large ? "{$large} 2400w" : null,
            ])),
        ];
    }
}
