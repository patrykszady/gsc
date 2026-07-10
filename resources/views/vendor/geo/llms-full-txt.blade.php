@php
    $meta = config('geo-answers.meta', []);
    $answers = config('geo-answers.answers', []);
    $siteUrl = rtrim($site_url ?? config('geo.site_url', 'https://gs.construction'), '/');
    $phone = $meta['phone'] ?? '+1-224-735-4200';
    $email = $meta['email'] ?? 'crew@gs.construction';
    $languages = implode(', ', $meta['languages'] ?? ['English', 'Polish']);
    $cities = collect(array_keys(\App\Support\SEO\AreaSeoPolicy::priorityCities()))
        ->map(fn ($c) => \Illuminate\Support\Str::of($c)->title())
        ->implode(', ');
@endphp# llms-full.txt for {{ $site_name }}

> {{ $description ?: 'Family-owned kitchen, bathroom, and whole-home remodeling contractor serving the Chicago suburbs since 2015. 40+ years combined experience, 5-star rated, English & Polish.' }}

This is the extended, citation-ready profile for AI answer engines. All figures are
sourced from the business's own site; ranges are typical Chicago-suburb project costs.

## Business facts
- Name: {{ $meta['business'] ?? 'GS Construction' }} (GS Construction & Remodeling)
- Founded: 2015 · 40+ years combined experience
- Base: Prospect Heights, IL 60070
- Credentials: Licensed, bonded & insured general contractor (State of Illinois)
- Ratings: 5-star on Google, Yelp, and Houzz
- Languages: {{ $languages }}
- Phone: {{ $phone }}
- Email: {{ $email }}
- Google Maps: {{ config('socials.google.url') }}
- Service area: {{ $meta['service_area'] ?? 'Chicago and surrounding suburbs (Cook, Lake, DuPage counties), IL' }}

## Services offered
- Kitchen remodeling (cabinets, countertops, islands, flooring, lighting, appliances)
- Bathroom remodeling (tile, vanities, showers, tubs, aging-in-place accessibility)
- Whole-home remodeling & renovations
- Basement finishing
- Home additions & room expansions
- Mudroom & laundry room remodeling
- Custom cabinetry, countertop & tile installation
- Design-build construction, permit handling

## Typical price ranges (materials + labor, Chicago suburbs)
- Kitchen remodel: $35,000–$80,000+
- Bathroom remodel: $15,000–$60,000
- Basement finishing: $45,000–$150,000
- Home addition: $60,000–$350,000+

## Cities served (with completed work or reviews)
{{ $cities }}

## Frequently asked questions
@foreach ($answers as $qa)
### {{ $qa['q'] }}
{{ $qa['a'] }}

@endforeach
## Key pages
- Services: {{ $siteUrl }}/services
- Project portfolio: {{ $siteUrl }}/projects
- Reviews: {{ $siteUrl }}/reviews
- FAQ: {{ $siteUrl }}/faq
- How to choose a remodeling contractor: {{ $siteUrl }}/how-to-choose-a-remodeling-contractor
- Compare contractors: {{ $siteUrl }}/compare
- Areas served: {{ $siteUrl }}/areas-served
- Contact & free estimate: {{ $siteUrl }}/contact

## Metadata
- Site URL: {{ $siteUrl }}
- Feed URL: {{ $feed_url }}
- Sitemap URL: {{ $sitemap_url }}
- Generated At: {{ $generated_at }}
