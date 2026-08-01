{{--
    Compact per-city unique content block.
    Reused on /areas-served/{city}/contact and /areas-served/{city}/services/*
    to differentiate them from the 88 other cities and break Google's
    near-duplicate clustering (Jaccard ≥ 0.85 on the 5-shingle audit).

    Variables required:
      $area      — App\Models\AreaServed
      $context   — string: 'contact' | 'kitchen-remodeling' | 'bathroom-remodeling'
                   | 'home-remodeling' | 'basement-remodeling' | 'home-additions'
                   (drives the heading + framing copy)
--}}
@php
    $ctx = $context ?? 'contact';

    // Headings vary by context so the H2 itself is unique per (city, service)
    // combo — Google heavily weights H2/H3 in dup detection.
    $heading = match ($ctx) {
        'kitchen-remodeling'  => "Kitchen remodeling in {$area->city}, IL",
        'bathroom-remodeling' => "Bathroom remodeling in {$area->city}, IL",
        'home-remodeling'     => "Whole-home remodeling in {$area->city}, IL",
        'basement-remodeling' => "Basement finishing in {$area->city}, IL",
        'home-additions'      => "Home additions in {$area->city}, IL",
        'contact'             => "Serving {$area->city}, IL homeowners",
        default               => "Remodeling in {$area->city}, IL",
    };

    // Project::project_type values, so the slider can show this trade's work.
    // 'contact' has no trade, so it keeps the mixed portfolio.
    $sliderType = match ($ctx) {
        'kitchen-remodeling'  => 'kitchen',
        'bathroom-remodeling' => 'bathroom',
        'home-remodeling'     => 'home-remodel',
        'basement-remodeling' => 'basement',
        'home-additions'      => 'addition',
        default               => null,
    };

    $serviceLine = match ($ctx) {
        'kitchen-remodeling'  => "kitchen remodels",
        'bathroom-remodeling' => "bathroom remodels",
        'home-remodeling'     => "whole-home remodels",
        'basement-remodeling' => "basement remodels",
        'home-additions'      => "home additions",
        default               => "remodeling projects",
    };
@endphp

@if($area->hasUniqueContent() || filled($area->landmarks) || filled($area->permit_notes))
    {{-- Renders through partials.area-intro-slider, the same block the area
         landing page uses — project slider left, city copy right. This file
         used to carry its own single-column, slider-less version, so a service
         page looked nothing like the landing page above it. All it owns now is
         the per-(city, service) heading and wording. --}}
    @include('partials.area-intro-slider', [
        'area' => $area,
        'heading' => $heading,
        'serviceLine' => $serviceLine,
        'projectType' => $sliderType,
    ])
@endif
