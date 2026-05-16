<div class="bg-white dark:bg-gray-900">
    {{-- Breadcrumb --}}
    @php
        $breadcrumbItems = [
            ['name' => 'Service Area', 'url' => url('/service-area')],
            ['name' => $city . ' (' . $zip . ')'],
        ];
    @endphp
    <x-breadcrumb-schema :items="$breadcrumbItems" />

    {{-- LocalBusiness JSON-LD scoped to this ZIP --}}
    @php
        $entityId = url('/service-area/' . $zip) . '#localbusiness';
        $zipSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            '@id' => $entityId,
            'name' => 'GS Construction',
            'description' => "Family-owned kitchen, bathroom and home remodeling contractor serving {$city}, IL " . $zip . " and the surrounding ZIP codes.",
            'url' => url('/service-area/' . $zip),
            'telephone' => '+1-224-735-4200',
            'email' => 'crew@gs.construction',
            'priceRange' => '$$$',
            'image' => asset('images/logo.svg'),
            'logo' => asset('images/logo.svg'),
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => $city,
                'addressRegion' => 'IL',
                'addressCountry' => 'US',
                'postalCode' => $zip,
            ],
            'areaServed' => [
                '@type' => 'PostalCodeSpecification',
                'postalCode' => $zip,
                'addressCountry' => 'US',
            ],
        ];
        if ($area && ! empty($area->latitude) && ! empty($area->longitude)) {
            $zipSchema['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => (float) $area->latitude,
                'longitude' => (float) $area->longitude,
            ];
        }
    @endphp
    <script type="application/ld+json">
{!! json_encode($zipSchema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>

    {{-- Visual breadcrumb --}}
    <div class="mx-auto max-w-7xl px-4 pt-6 sm:px-6 lg:px-8">
        <nav class="flex text-sm" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2">
                <li><a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300">Home</a></li>
                <li class="text-gray-400">/</li>
                <li><a href="{{ url('/service-area') }}" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300">Service Area</a></li>
                <li class="text-gray-400">/</li>
                <li class="font-medium text-gray-900 dark:text-white">{{ $city }} ({{ $zip }})</li>
            </ol>
        </nav>
    </div>

    {{-- Hero --}}
    <section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-amber-600">Service area</p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">
            Home Remodeling in {{ $city }}, IL &mdash; ZIP {{ $zip }}
        </h1>
        <p class="mt-4 max-w-3xl text-lg text-gray-600 dark:text-gray-300">
            GS Construction is a family-owned, licensed remodeling contractor serving the
            <strong>{{ $zip }}</strong> ZIP code in <strong>{{ $city }}, Illinois</strong>.
            Free in-home estimates for kitchen, bathroom, and whole-home renovations.
            @if ($projectCount > 0)
                We've completed <strong>{{ $projectCount }}</strong> projects in this ZIP.
            @endif
        </p>

        <div class="mt-6 flex flex-wrap gap-3">
            <a href="/contact" wire:navigate
                class="inline-flex items-center rounded-md bg-amber-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-amber-700">
                Request free estimate
            </a>
            <a href="tel:+12247354200"
                class="inline-flex items-center rounded-md bg-white px-5 py-2.5 text-sm font-semibold text-gray-900 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-700">
                Call (224) 735-4200
            </a>
            @if ($area)
                <a href="{{ $area->url }}" wire:navigate
                    class="inline-flex items-center rounded-md bg-white px-5 py-2.5 text-sm font-semibold text-gray-900 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-700">
                    See {{ $city }} area page &rarr;
                </a>
            @endif
        </div>
    </section>

    {{-- Services in this ZIP --}}
    <section class="mx-auto max-w-7xl px-4 pb-12 sm:px-6 lg:px-8">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
            Remodeling services available in {{ $city }} {{ $zip }}
        </h2>
        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            @php
                $services = [
                    ['slug' => 'kitchen-remodeling', 'label' => "Kitchen Remodeling in {$city}"],
                    ['slug' => 'bathroom-remodeling', 'label' => "Bathroom Remodeling in {$city}"],
                    ['slug' => 'home-remodeling', 'label' => "Whole-Home Remodeling in {$city}"],
                ];
            @endphp
            @foreach ($services as $svc)
                @php
                    $href = $area
                        ? url('/areas-served/' . $area->slug . '/services/' . $svc['slug'])
                        : url('/services/' . $svc['slug']);
                @endphp
                <a href="{{ $href }}" wire:navigate
                    class="block rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition hover:border-amber-500 hover:shadow-md dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">{{ $svc['label'] }}</p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Free estimates &middot; Licensed &middot; Insured</p>
                </a>
            @endforeach
        </div>
    </section>

    {{-- Projects in this ZIP --}}
    @if ($projects->isNotEmpty())
        <section class="mx-auto max-w-7xl px-4 pb-12 sm:px-6 lg:px-8">
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
                Recent projects in {{ $city }} {{ $zip }}
            </h2>
            <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($projects as $project)
                    @php $img = optional($project->images->first())->url; @endphp
                    <a href="{{ route('projects.show', $project->slug) }}" wire:navigate
                        class="group block overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200 transition hover:shadow-md dark:bg-gray-800 dark:ring-gray-700">
                        @if ($img)
                            <img src="{{ $img }}" alt="{{ $project->title }} in {{ $city }}, IL"
                                loading="lazy" class="aspect-4/3 w-full object-cover" />
                        @endif
                        <div class="p-4">
                            <p class="text-base font-semibold text-gray-900 group-hover:text-amber-700 dark:text-white">
                                {{ $project->title }}
                            </p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $project->location }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Local context --}}
    @if ($area)
        <section class="mx-auto max-w-7xl px-4 pb-16 sm:px-6 lg:px-8">
            <div class="rounded-lg bg-gray-50 p-6 dark:bg-gray-800">
                <h2 class="text-xl font-bold text-gray-900 dark:text-white">About ZIP {{ $zip }} in {{ $city }}, IL</h2>
                @if ($area->local_intro)
                    <p class="mt-3 text-gray-700 dark:text-gray-300">{{ $area->local_intro }}</p>
                @elseif ($area->intro)
                    <p class="mt-3 text-gray-700 dark:text-gray-300">{{ $area->intro }}</p>
                @endif
                @if ($area->permit_notes)
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-400"><strong>Permit notes:</strong> {{ $area->permit_notes }}</p>
                @endif
            </div>
        </section>
    @endif

    {{-- City + ZIP Google Map iframe --}}
    <x-area-google-map
        :query="$city . ', IL ' . $zip"
        :heading="'Map of ' . $city . ', IL ' . $zip"
        :title="'Map of ' . $city . ', IL ZIP ' . $zip" />
</div>
