<?php

namespace App\Support\Citations;

use App\Models\Project;
use Illuminate\Support\Str;

/**
 * The one description of the business every directory gets: identity,
 * contact, address, hours, categories, services, descriptions in three
 * lengths, the logo and the best project photos with captions. Built from
 * config/brand.php, config/gbp-services.php, config/socials.php and the
 * published projects — nothing is typed per directory.
 */
class ListingPayload
{
    public static function make(): array
    {
        $brand = (array) config('brand', []);
        $address = (array) ($brand['address'] ?? []);
        $stats = self::stats();
        $services = collect((array) config('gbp-services.services', []))->map(fn ($s) => (string) ($s['name'] ?? ''))->filter()->values()->all();
        $areas = collect((array) config('gbp-services.service_areas', []))->map(fn ($a) => trim((string) Str::before((string) $a, ',')))->filter()->unique()->values()->all();
        $founded = (int) ($brand['founded'] ?? 2015);
        $short = Str::limit(sprintf('%s: kitchen, bath and home remodeling in the Chicago suburbs. Family-owned, %s. Free estimates.', $brand['display_name'] ?? $brand['name'] ?? '', $stats['reviews'] ? $stats['reviews'] . ' five-star reviews' : 'five-star rated'), 160, '');
        $medium = sprintf(
            '%s is a family-owned kitchen, bathroom and home remodeling contractor based in %s, %s, founded in %d by %s. We handle kitchens, bathrooms, basements, additions and whole-home renovations across %s and %d+ Chicago suburbs, with %s and free in-home estimates. Licensed, insured and bonded. We work in %s.',
            $brand['display_name'] ?? $brand['name'] ?? '', $address['city'] ?? $brand['city'] ?? '', $address['state'] ?? $brand['state'] ?? '', $founded, $brand['owners'] ?? 'the owners',
            implode(', ', array_slice($areas, 0, 4)), max(1, count($areas)), $stats['reviews'] ? $stats['reviews'] . ' verified five-star reviews on Google, Houzz, Yelp and Angi' : 'five-star reviews',
            implode(' and ', (array) ($brand['languages'] ?? ['English']))
        );
        $long = $medium . ' ' . 'Every project starts with a written, itemized estimate. Bring your own designer or architect, or work directly with the owners on layout, cabinetry, tile, plumbing and electrical. Popular projects: ' . implode(', ', array_slice($services, 0, 6)) . '.';

        return [
            'name' => (string) ($brand['display_name'] ?? $brand['name'] ?? ''),
            'short_name' => (string) ($brand['name'] ?? ''),
            'legal_name' => (string) ($brand['legal_name'] ?? ''),
            'also_known_as' => (string) ($brand['also_known_as'] ?? ''),
            'contact' => [
                'first_name' => (string) config('citations.contact.first_name'),
                'last_name' => (string) config('citations.contact.last_name'),
                'title' => (string) config('citations.contact.title', 'Owner'),
                'owners' => (string) ($brand['owners'] ?? ''),
            ],
            'phone' => (string) ($brand['phone'] ?? ''),
            'phone_digits' => preg_replace('/\D+/', '', (string) ($brand['phone_href'] ?? $brand['phone'] ?? '')),
            'email' => (string) ($brand['email'] ?? ''),
            'website' => rtrim((string) config('app.url'), '/') . '/',
            'address' => [
                'street' => (string) ($address['street'] ?? ''),
                'city' => (string) ($address['city'] ?? $brand['city'] ?? ''),
                'state' => (string) ($address['state'] ?? $brand['state'] ?? ''),
                'state_name' => 'Illinois',
                'zip' => (string) ($address['zip'] ?? ''),
                'country' => (string) ($address['country'] ?? 'US'),
                'lat' => $address['lat'] ?? null,
                'lng' => $address['lng'] ?? null,
                'full' => trim(sprintf('%s, %s, %s %s', $address['street'] ?? '', $address['city'] ?? $brand['city'] ?? '', $address['state'] ?? $brand['state'] ?? '', $address['zip'] ?? ''), ', '),
            ],
            'hours' => (array) ($brand['hours'] ?? []),
            'hours_text' => self::hoursText((array) ($brand['hours'] ?? [])),
            'founded' => $founded,
            'languages' => (array) ($brand['languages'] ?? ['English']),
            'categories' => ['Kitchen remodeler', 'Bathroom remodeler', 'Remodeler', 'General contractor', 'Construction company'],
            'services' => $services,
            'service_areas' => $areas,
            'description' => ['short' => $short, 'medium' => $medium, 'long' => $long],
            'stats' => $stats,
            'social' => array_filter([
                'facebook' => config('socials.facebook.url'),
                'instagram' => config('socials.instagram.url'),
                'google' => config('socials.google.url'),
                'houzz' => config('socials.houzz.url'),
                'yelp' => config('socials.yelp.url'),
                'angi' => config('socials.angi.url'),
            ]),
            'profiles' => (array) ($brand['profiles'] ?? []),
            'logo' => [
                'svg' => asset('images/logo.svg'),
                'png' => asset('images/og-default.jpg'),
            ],
            'photos' => self::photos(),
        ];
    }

    /** Best published photos: featured projects first, a few per project, with captions. */
    public static function photos(?int $max = null, ?int $perProject = null): array
    {
        $max = $max ?? (int) config('citations.photos.max', 40);
        $perProject = $perProject ?? (int) config('citations.photos.per_project', 3);
        $minWidth = (int) config('citations.photos.min_width', 800);
        $out = [];
        $projects = Project::query()->where('is_published', true)->with('images')
            ->orderByDesc('is_featured')->orderByDesc('completed_at')->orderByDesc('id')->get();
        foreach ($projects as $project) {
            $n = 0;
            foreach ($project->images as $image) {
                if ($n >= $perProject || count($out) >= $max) {
                    break;
                }
                if ($image->width && $image->width < $minWidth) {
                    continue;
                }
                $url = $image->url ?? null;
                if (! $url) {
                    continue;
                }
                $out[] = [
                    'url' => self::absolute($url),
                    'caption' => trim(($image->caption ?: $image->alt_text ?: $project->title) . ($project->location ? ' — ' . $project->location : '')),
                    'project' => $project->title,
                    'project_url' => url('/projects/' . $project->slug),
                ];
                $n++;
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /** Absolute URL on the site's own scheme (the public disk may still answer http:// behind the proxy). */
    protected static function absolute(string $url): string
    {
        $url = Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
        if (Str::startsWith((string) config('app.url'), 'https://') && Str::startsWith($url, 'http://')) {
            $url = 'https://' . substr($url, 7);
        }

        return $url;
    }

    /** "Mon–Sat 8:00 AM–6:00 PM, Sun closed" from the config hours map. */
    public static function hoursText(array $hours): string
    {
        if ($hours === []) {
            return '';
        }
        $parts = [];
        foreach ($hours as $day => $span) {
            $parts[] = $span ? sprintf('%s %s–%s', $day, self::ampm($span[0]), self::ampm($span[1])) : "{$day} closed";
        }

        return implode(', ', $parts);
    }

    protected static function ampm(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [0, 0]);
        $suffix = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12 ?: 12;

        return $m ? sprintf('%d:%02d %s', $h12, $m, $suffix) : sprintf('%d %s', $h12, $suffix);
    }

    protected static function stats(): array
    {
        $reviews = 0;
        try {
            if (class_exists(\App\Models\Testimonial::class)) {
                $q = \App\Models\Testimonial::query();
                if (\Illuminate\Support\Facades\Schema::hasColumn('testimonials', 'is_published')) {
                    $q->where('is_published', true);
                }
                $reviews = (int) $q->count();
            }
        } catch (\Throwable) {
            $reviews = 0;
        }
        $projects = (int) Project::query()->where('is_published', true)->count();

        return ['reviews' => $reviews, 'projects' => $projects];
    }
}
