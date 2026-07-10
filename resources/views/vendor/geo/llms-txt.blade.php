@php
    $meta = config('geo-answers.meta', []);
    $siteUrl = rtrim($site_url ?? config('geo.site_url', 'https://gs.construction'), '/');
    $phone = $meta['phone'] ?? '+1-224-735-4200';
    $email = $meta['email'] ?? 'crew@gs.construction';
    $languages = implode(', ', $meta['languages'] ?? ['English', 'Polish']);
    $cities = collect(array_keys(\App\Support\SEO\AreaSeoPolicy::priorityCities()))
        ->map(fn ($c) => \Illuminate\Support\Str::of($c)->title())
        ->take(18)->implode(', ');
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

## Key pages
- [Services]({{ $siteUrl }}/services)
- [Project portfolio]({{ $siteUrl }}/projects)
- [Reviews]({{ $siteUrl }}/reviews)
- [FAQ]({{ $siteUrl }}/faq)
- [How to choose a remodeling contractor]({{ $siteUrl }}/how-to-choose-a-remodeling-contractor)
- [Areas served]({{ $siteUrl }}/areas-served)
- [Contact & free estimate]({{ $siteUrl }}/contact)

## Contact
- Phone: {{ $phone }}
- Email: {{ $email }}
- Website: {{ $siteUrl }}
- Google Maps: {{ config('socials.google.url') }}

## Metadata
- Full version: {{ $siteUrl }}/llms-full.txt
- Feed URL: {{ $feed_url }}
- Sitemap URL: {{ $sitemap_url }}
- Generated At: {{ $generated_at }}
