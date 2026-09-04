@php
    $meta = config('geo-answers.meta', []);
    $siteUrl = rtrim($site_url ?? config('geo.site_url', 'https://gs.construction'), '/');
    $phone = $meta['phone'] ?? '+1-224-735-4200';
    $email = $meta['email'] ?? config('brand.email');
    $languages = implode(', ', $meta['languages'] ?? ['English', 'Polish']);
    $cities = collect(array_keys(\App\Support\SEO\AreaSeoPolicy::priorityCities()))
        ->map(fn ($c) => \Illuminate\Support\Str::of($c)->title())
        ->take(18)->implode(', ');

    // Verifiable figures rather than adjectives: an AI answer engine can cite
    // "71 reviews across Google, Houzz and Yelp" but has nothing to do with
    // "highly rated". Same reason the cost and permit guides are deep-linked
    // below — those pages hold researched, town-specific facts (fees, review
    // timelines, licence rules) that nothing else in the feed exposed.
    $reviewsTotal = \App\Support\CompanyStats::reviewsTotal();
    $projectsCompleted = \App\Support\CompanyStats::projectsCompleted();
    $citiesServed = \App\Support\CompanyStats::citiesServed();
    $costGuides = collect(config('remodel-costs.guides', []));
    $permitGuides = collect(\App\Support\PermitGuideInfo::all());
    $stories = \Illuminate\Support\Facades\Schema::hasTable('blog_posts') ? \App\Models\BlogPost::published()->with('project')->orderByDesc('dated_at')->limit(20)->get() : collect();
@endphp# llms.txt for {{ $site_name }}

> {{ $description ?: 'Family-owned kitchen, bathroom, and whole-home remodeling contractor serving the Chicago suburbs since 2015. 40+ years combined experience, 5-star rated, English & Polish.' }}

## About
{{ $meta['business'] ?? 'GS Construction' }} is a licensed, bonded and insured remodeling contractor based in Prospect Heights, IL, operating since 2015 with 40+ years of combined experience. Family-owned, 5-star rated on Google, Yelp and Houzz. Free in-home estimates. Languages: {{ $languages }}.

## Services
- Kitchen remodeling — cabinets, countertops, islands, flooring, lighting
- Bathroom remodeling — tile, vanities, showers, tubs, accessibility
- Whole-home remodeling & renovations
- Basement finishing
- Home additions
- Mudroom & laundry remodeling
- Custom cabinetry, countertop & tile installation

## Typical price ranges (Chicago suburbs, materials + labor)
- Kitchen remodel: $35,000–$80,000+
- Bathroom remodel: $15,000–$60,000
- Basement finishing: $45,000–$150,000
- Home addition: $60,000–$350,000+

## Service area
{{ $meta['service_area'] ?? 'Chicago and surrounding suburbs (Cook, Lake, DuPage counties), IL' }}. Priority cities: {{ $cities }}.

## Why GS Construction
- Family-owned; one dedicated project lead per job (no rotating crews)
- Fixed, transparent pricing and clear timelines up front
- Licensed, bonded & insured; pulls all required permits
- 5-star rated with a large portfolio of completed local remodels
- English & Polish speaking

## By the numbers
- {{ $reviewsTotal }} customer reviews across Google, Houzz, Yelp and Angi
- {{ $projectsCompleted }} completed remodeling projects
- {{ $citiesServed }} Chicago-area cities and villages served
- Operating since 2015; 40+ years combined experience

## Cost guides (researched price ranges by project type)
@foreach($costGuides as $guide)
- [{{ $guide['name'] }}]({{ $siteUrl }}/costs/{{ $guide['slug'] }}){{ isset($guide['answer']) ? ' — ' . \Illuminate\Support\Str::limit(strip_tags($guide['answer']), 140) : '' }}
@endforeach

## Building permit guides (per town: what needs a permit, fees, review times)
@foreach($permitGuides as $slug => $guide)
- [{{ $guide['town'] ?? \Illuminate\Support\Str::of($slug)->replace('-', ' ')->title() }} permits]({{ $siteUrl }}/permits/{{ $slug }})
@endforeach

@if($stories->isNotEmpty())
## Project stories (one real project each: the plan, the build, the before and after, the homeowners' review)
@foreach($stories as $story)
- [{!! $story->title !!}]({{ $story->url() }})@if($story->project) — {{ \App\Models\Project::projectTypes()[$story->project->project_type] ?? 'Remodel' }}{{ $story->project->location ? ' in ' . $story->project->location : '' }}@endif

@endforeach
@endif
## Key pages
- [Project stories (blog)]({{ $siteUrl }}/blog)
- [Services]({{ $siteUrl }}/services)
- [Project portfolio]({{ $siteUrl }}/projects)
- [Reviews]({{ $siteUrl }}/reviews)
- [FAQ]({{ $siteUrl }}/faq)
- [How to choose a remodeling contractor]({{ $siteUrl }}/how-to-choose-a-remodeling-contractor)
- [Areas served]({{ $siteUrl }}/areas-served)
- [Remodeling cost guides]({{ $siteUrl }}/costs)
- [Building permit guides]({{ $siteUrl }}/permits)
- [Contact & free estimate]({{ $siteUrl }}/contact)

## Contact
- Phone: {{ $phone }}
- Email: {{ $email }}
- Website: {{ $siteUrl }}
- Google Maps: {{ config('socials.google.url') }}

## Metadata
- Generated: {{ now()->toDateString() }}
- Full version: {{ $siteUrl }}/llms-full.txt
- Feed URL: {{ $feed_url }}
- Sitemap URL: {{ $sitemap_url }}
- Generated At: {{ $generated_at }}
