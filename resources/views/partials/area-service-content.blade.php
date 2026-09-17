{{--
    The copy one service page carries in one town, written for that pair
    (AreaServiceContent), followed by the town's own copy as the town page
    shows it — the lead and the folded "how it was built" / "what that means
    for remodeling" (Patryk's call, 2026-09-17: on every page, not a link).
    Before a pair has its copy, the shared town block renders so the page
    never goes thin.
    Variables: $area (AreaServed), $config (the service page's config array).
--}}
@php
    $serviceCopy = $area->serviceContent($config['urlSlug']);
    $heading = match ($config['urlSlug']) {
        'kitchen-remodeling'  => "Kitchen remodeling in {$area->city}, IL",
        'bathroom-remodeling' => "Bathroom remodeling in {$area->city}, IL",
        'home-remodeling'     => "Whole-home remodeling in {$area->city}, IL",
        'basement-remodeling' => "Basement finishing in {$area->city}, IL",
        'home-additions'      => "Home additions in {$area->city}, IL",
        default               => "{$config['label']} in {$area->city}, IL",
    };
@endphp

{{-- One layout for both cases (partials/area-intro-slider): the trade's
     photos on the left, sticky; on the right the pair's own copy with the
     town's folds under it, or — until the pair has its copy — the town block. --}}
@php
    $serviceLabel = \App\Models\AreaServiceContent::label($config['urlSlug']);
    $serviceLine = str_replace('remodeling', 'remodels', $serviceLabel); // "kitchen remodels", "basement finishing", "home additions"
@endphp
@if ($serviceCopy || $area->hasUniqueContent() || filled($area->landmarks) || filled($area->permit_notes))
    @include('partials.area-intro-slider', [
        'area' => $area,
        'heading' => $heading,
        'serviceLine' => $serviceLine,
        'projectType' => $config['projectType'] ?? null,
        'serviceCopy' => $serviceCopy,
        'serviceLabel' => $serviceLabel,
    ])
@endif
