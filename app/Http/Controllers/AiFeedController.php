<?php

namespace App\Http\Controllers;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Testimonial;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Machine-readable structured feed for AI crawlers (ChatGPT, Perplexity,
 * Google AI Overviews, Claude, etc.). Linked from llms.txt and robots.txt
 * so generative engines can ingest a clean, current snapshot of the
 * business without scraping rendered pages.
 */
class AiFeedController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $payload = Cache::remember('ai_feed_v1', 900, function (): array {
            $reviewCount = Testimonial::count();

            $services = [
                ['slug' => 'kitchen-remodeling',  'name' => 'Kitchen Remodeling',  'description' => 'Full kitchen renovations: cabinets, countertops, flooring, lighting, appliances.'],
                ['slug' => 'bathroom-remodeling', 'name' => 'Bathroom Remodeling', 'description' => 'Tile work, vanities, showers, bathtubs, fixtures.'],
                ['slug' => 'home-remodeling',     'name' => 'Home Remodeling',     'description' => 'Whole-home renovations and transformations.'],
                ['slug' => 'basement-remodeling', 'name' => 'Basement Remodeling', 'description' => 'Basement finishing and renovation.'],
                ['slug' => 'home-additions',      'name' => 'Home Additions',      'description' => 'Room additions and home expansion services.'],
            ];

            $projects = Project::where('is_published', true)
                ->orderByDesc('is_featured')
                ->orderByDesc('completed_at')
                ->limit(50)
                ->get()
                ->map(fn (Project $p) => array_filter([
                    'id'           => $p->id,
                    'title'        => $p->title,
                    'slug'         => $p->slug,
                    'url'          => url('/projects/' . $p->slug),
                    'description'  => $p->description,
                    'project_type' => $p->project_type,
                    'location'     => $p->location,
                    'completed_at' => optional($p->completed_at)->toDateString(),
                    'is_featured'  => (bool) $p->is_featured,
                ]))
                ->values()
                ->all();

            $reviews = Testimonial::visible()
                ->latest('review_date')
                ->limit(50)
                ->get()
                ->map(fn (Testimonial $t) => array_filter([
                    'id'           => $t->id,
                    'author'       => $t->display_name,
                    'rating'       => $t->star_rating,
                    'review_date'  => optional($t->review_date)->toDateString(),
                    'project_type' => $t->project_type,
                    'location'     => $t->project_location,
                    'body'         => $t->review_description,
                    'sources'      => $t->reviewUrls->map(fn ($u) => [
                        'platform' => $u->platform,
                        'url'      => $u->url,
                    ])->values()->all(),
                ]))
                ->values()
                ->all();

            $areas = AreaServed::orderBy('city')->pluck('city')->all();

            return [
                '$schema'      => 'https://gs.construction/ai-feed.schema.json',
                'generated_at' => now()->toIso8601String(),
                'business' => [
                    'name'         => 'GS Construction',
                    'legal_name'   => 'GS Construction & Remodeling',
                    'url'          => 'https://gs.construction',
                    'phone'        => '+1-224-735-4200',
                    'email'        => 'crew@gs.construction',
                    'founded'      => '2015',
                    'languages'    => ['English', 'Polish'],
                    'address'      => [
                        'locality'     => 'Arlington Heights',
                        'region'       => 'IL',
                        'country'      => 'US',
                    ],
                    'hours'        => 'Mon-Sat 08:00-18:00 America/Chicago',
                    'rating'       => [
                        'value'    => 5,
                        'count'    => $reviewCount,
                        'sources'  => ['Google', 'Houzz', 'Yelp', 'Angi'],
                    ],
                    'social'       => array_filter([
                        'facebook'  => config('socials.facebook.url'),
                        'instagram' => config('socials.instagram.url'),
                        'google'    => config('socials.google.url'),
                        'houzz'     => config('socials.houzz.url'),
                        'yelp'      => config('socials.yelp.url'),
                        'angi'      => config('socials.angi.url'),
                    ]),
                ],
                'services'     => $services,
                'service_area' => $areas,
                'projects'     => $projects,
                'reviews'      => $reviews,
                'links' => [
                    'sitemap'    => url('/sitemap.xml'),
                    'llms_txt'   => url('/llms.txt'),
                    'llms_full'  => url('/llms-full.txt'),
                    'robots'     => url('/robots.txt'),
                ],
            ];
        });

        return response()
            ->json($payload, 200, [], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            ->header('Cache-Control', 'public, max-age=900')
            ->header('X-Robots-Tag', 'all');
    }
}
