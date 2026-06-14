<?php

namespace App\Support;

/**
 * Resolves curated, self-hosted "representative" service imagery for service
 * types that have no real completed-project photos yet (basement & additions).
 *
 * Single source of truth: config/service-images.php. Everything that needs a
 * basement/addition fallback image (hero sliders, service grid, OG/share tags,
 * area-service pages) goes through here so the imagery — and its honest
 * "representative" labelling — stays consistent site-wide.
 */
class ServiceImages
{
    /**
     * Normalise both internal project types and URL slugs to a config key.
     */
    public static function normalizeType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'basement', 'basement-remodeling', 'basement-finishing' => 'basement',
            'addition', 'home-additions', 'home-addition', 'room-additions' => 'addition',
            default => strtolower(trim($type)),
        };
    }

    public static function has(string $type): bool
    {
        $set = config('service-images.' . self::normalizeType($type));

        return is_array($set) && ! empty($set['images']);
    }

    /**
     * All curated images for a type, each as ['url' => ..., 'alt' => ...] with
     * an honest "representative" alt suffix (city-specific when provided).
     *
     * @return array<int, array{url: string, alt: string}>
     */
    public static function all(string $type, ?string $city = null): array
    {
        $key = self::normalizeType($type);
        $set = config("service-images.$key");

        if (! is_array($set) || empty($set['images'])) {
            return [];
        }

        $label = $set['label'] ?? 'remodeling project';
        $where = $city ? "{$city}, IL" : 'the Chicago suburbs';
        $suffix = " — representative of the {$label} projects GS Construction builds in {$where}";

        return collect($set['images'])
            ->filter(fn ($img) => ! empty($img['src']))
            ->map(fn ($img) => [
                'url' => asset($img['src']),
                'alt' => ($img['alt'] ?? "Representative {$label}") . $suffix,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{url: string, alt: string}|null
     */
    public static function first(string $type, ?string $city = null): ?array
    {
        return self::all($type, $city)[0] ?? null;
    }

    public static function firstUrl(string $type): ?string
    {
        return self::first($type)['url'] ?? null;
    }

    public static function randomUrl(string $type): ?string
    {
        $all = self::all($type);

        return $all === [] ? null : $all[array_rand($all)]['url'];
    }

    /**
     * Honest alt text for a given self-hosted service image URL (matched by
     * filename), used when only the URL is available downstream.
     */
    public static function altForUrl(string $url, ?string $city = null): ?string
    {
        if (! str_contains($url, '/images/services/')) {
            return null;
        }

        $file = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        $type = str_starts_with($file, 'basement') ? 'basement'
            : (str_starts_with($file, 'addition') ? 'addition' : null);

        if ($type === null) {
            return null;
        }

        foreach (self::all($type, $city) as $img) {
            if (basename(parse_url($img['url'], PHP_URL_PATH) ?: '') === $file) {
                return $img['alt'];
            }
        }

        return self::first($type, $city)['alt'] ?? null;
    }
}
