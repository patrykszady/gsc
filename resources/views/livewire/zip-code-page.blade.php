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
            'image' => (\App\Models\ProjectImage::curatedCover()?->url) ?: asset('images/greg-patryk.jpg'),
            'logo' => asset('android-chrome-512x512.png'),
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

    {{-- Product schema for the services surfaced on this ZIP page — city-scoped
         when we have a matching area — so long-tail "{service} {zip}" / "{service}
         in {city}" queries are eligible for review-star + offer rich results.
         Slugs mirror the visible "services available in {city}" list below. --}}
    @foreach (['kitchen-remodeling', 'bathroom-remodeling', 'home-remodeling'] as $zipServiceSlug)
        <x-product-service-schema :service-slug="$zipServiceSlug" :area="$area" />
    @endforeach

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
    {{-- Visual hero (project slider + overlay), matching /permits and /trades --}}
    <div class="mx-auto mt-4 w-full max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="relative overflow-hidden rounded-2xl">
            <livewire:main-project-hero-slider
                :images-only="true"
                height-classes="h-[300px] sm:h-[380px] lg:h-[440px]"
                :slides="[
                    ['projectType' => 'kitchen'],
                    ['projectType' => 'bathroom'],
                    ['projectType' => 'home-remodel'],
                ]"
                :key="'zip-hero-' . $zip"
            />
            <div class="pointer-events-none absolute inset-0 z-10 flex items-end bg-linear-to-t from-black/80 via-black/40 to-transparent pb-10 sm:pb-12 lg:pb-14">
                <div class="mx-auto w-full max-w-7xl px-6 lg:px-8">
                    <p class="text-sm font-semibold uppercase tracking-wide text-sky-300">Service area</p>
                    <h1 class="mt-1 font-heading text-3xl font-bold text-white text-shadow-lg sm:text-4xl lg:text-5xl">
                        Home Remodeling in {{ $city }}, IL &mdash; ZIP {{ $zip }}
                    </h1>
                    @if ($hiveZipCount > 0)
                        <p class="mt-2 max-w-2xl text-base text-white/90 text-shadow-sm sm:text-lg">
                            {{ number_format($hiveZipCount) }} {{ \Illuminate\Support\Str::plural('project', $hiveZipCount) }} completed in this ZIP — family-owned, licensed &amp; insured.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <p class="max-w-3xl text-lg text-gray-600 dark:text-gray-300">
            @if ($zipIntro)
                {{ $zipIntro }}
            @else
                GS Construction is a family-owned, licensed remodeling contractor serving the
                <strong>{{ $zip }}</strong> ZIP code in <strong>{{ $city }}, Illinois</strong>.
                Free in-home estimates for kitchen, bathroom, and whole-home renovations.
            @endif
            @if ($projectCount > 0)
                We've completed <strong>{{ $projectCount }}</strong> projects in and around this ZIP.
            @endif
        </p>

        <div class="mt-6 flex flex-wrap gap-3">
            <a href="/contact" wire:navigate
                class="inline-flex items-center rounded-md bg-sky-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-sky-700">
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

        {{-- Why homeowners here choose GS --}}
        <div class="mt-10 grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <p class="text-base font-semibold text-gray-900 dark:text-white">One contract, one project lead</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Greg &amp; Patryk run every job with licensed trade partners under GS supervision — see <a href="{{ route('process') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">how the process works</a>.</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <p class="text-base font-semibold text-gray-900 dark:text-white">Permits handled for you</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Application, village registration, and every inspection through final sign-off — on every {{ $city }} project.</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <p class="text-base font-semibold text-gray-900 dark:text-white">Written warranty</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Workmanship stands behind every remodel — read the <a href="{{ route('warranty') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">GS warranty</a>.</p>
            </div>
        </div>
    </section>

    {{-- Per-ZIP completed-project proof (exact Hive count for this ZIP) --}}
    @if ($hiveZipCount > 0)
        <section class="mx-auto max-w-7xl px-4 pb-10 sm:px-6 lg:px-8">
            <div class="rounded-2xl border border-sky-200 bg-sky-50 p-6 dark:border-sky-500/20 dark:bg-sky-500/5">
                <p class="text-base leading-7 text-zinc-700 dark:text-zinc-300">
                    <strong class="font-semibold text-zinc-900 dark:text-white">GS Construction crews have completed {{ number_format($hiveZipCount) }} {{ \Illuminate\Support\Str::plural('project', $hiveZipCount) }} in ZIP {{ $zip }} alone</strong>
                    — real jobs from our project records, not a service-area claim. The map at the bottom of
                    this page shows how that work spreads across {{ $city }} and the surrounding ZIP codes.
                </p>
            </div>
        </section>
    @endif

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
                    ['slug' => 'basement-remodeling', 'label' => "Basement Remodeling in {$city}"],
                    ['slug' => 'home-additions', 'label' => "Home Additions in {$city}"],
                ];
            @endphp
            @foreach ($services as $svc)
                @php
                    $href = $area
                        ? url('/areas-served/' . $area->slug . '/services/' . $svc['slug'])
                        : url('/services/' . $svc['slug']);
                @endphp
                <a href="{{ $href }}" wire:navigate
                    class="block rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition hover:border-sky-500 hover:shadow-md dark:border-gray-700 dark:bg-gray-800">
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
                            <p class="text-base font-semibold text-gray-900 group-hover:text-sky-700 dark:text-white">
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
                @if ($zipLocalContext)
                    <p class="mt-3 text-gray-700 dark:text-gray-300">{{ $zipLocalContext }}</p>
                @elseif ($area->local_intro)
                    <p class="mt-3 text-gray-700 dark:text-gray-300">{{ $area->local_intro }}</p>
                @elseif ($area->intro)
                    <p class="mt-3 text-gray-700 dark:text-gray-300">{{ $area->intro }}</p>
                @endif

                @if ($zipLandmarks)
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-400"><strong>Local landmarks:</strong> {{ $zipLandmarks }}</p>
                @endif

                @if ($zipPermitNotes)
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-400"><strong>Permit notes:</strong> {{ $zipPermitNotes }}</p>
                @elseif ($area->permit_notes)
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-400"><strong>Permit notes:</strong> {{ $area->permit_notes }}</p>
                @endif
            </div>
        </section>
    @endif

    {{-- Town-attributed review quotes (real reviewer towns, honest nearby fallback) --}}
    @if ($area)
        @include('livewire.partials.town-review-quotes')
    @endif

    {{-- Homeowner guides for this ZIP's town: permits, lead lines, costs --}}
    @if ($area)
        @php
            $zipPermitGuide = \App\Support\PermitGuideInfo::forSlug($area->slug) !== null;
        @endphp
        <section class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8" aria-label="Homeowner guides for {{ $city }}">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">{{ $city }} homeowner guides</h2>
            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <a href="{{ $zipPermitGuide ? route('permits.show', ['slug' => $area->slug]) : route('permits.index') }}" wire:navigate
                   class="block rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-sky-500 hover:shadow-md dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">Building permits in {{ $city }}</p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Who needs one, fees, review times &amp; inspections — from official village sources.</p>
                </a>
                <a href="{{ route('areas.lead-line', ['area' => $area->slug]) }}" wire:navigate
                   class="block rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-sky-500 hover:shadow-md dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">Lead pipe replacement in {{ $city }}</p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Who pays, how to check your line, and what it means mid-remodel.</p>
                </a>
                <a href="{{ route('costs.index') }}" wire:navigate
                   class="block rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-sky-500 hover:shadow-md dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">What remodeling really costs</p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Real {{ now()->year }} price ranges for kitchens, baths, basements &amp; additions.</p>
                </a>
            </div>
        </section>
    @endif

    {{-- ZIP FAQ (visible + FAQPage schema) --}}
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <x-faq-section
            heading="Remodeling in {{ $zip }} — common questions"
            :collapsed="false"
            :faqs="[
                ['question' => 'Do you serve ZIP code ' . $zip . '?', 'answer' => 'Yes — ' . $city . ' (' . $zip . ') is part of GS Construction\'s core service area' . ($hiveZipCount > 0 ? ', where our crews have completed ' . $hiveZipCount . ' ' . \Illuminate\Support\Str::plural('project', $hiveZipCount) . ' to date' : '') . '. We provide free in-home estimates throughout the area.'],
                ['question' => 'Who pulls the building permit for a remodel in ' . $city . '?', 'answer' => 'We do. GS Construction prepares the drawings and application, registers with the village where required, and schedules every inspection through final sign-off on all ' . $city . ' projects.'],
                ['question' => 'What remodeling services do you offer in ' . $zip . '?', 'answer' => 'Kitchen remodeling, bathroom remodeling, basement finishing, home additions, and whole-home renovations — one contract and one project lead, with licensed trade partners under GS supervision.'],
                ['question' => 'How do I get a quote for a project in ' . $city . '?', 'answer' => 'Request a free in-home estimate — we visit the home, discuss the scope, and deliver an itemized quote. There is no charge and no pressure at any point.'],
            ]"
        />
    </div>

    {{-- Nearby served ZIPs with their own completed-project counts --}}
    @if (!empty($nearbyZips))
        <section class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8" aria-label="Nearby ZIP codes we serve">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Nearby ZIP codes we serve</h2>
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($nearbyZips as $nz)
                    <a href="{{ url('/service-area/' . $nz['zip']) }}" wire:navigate
                       class="rounded-lg bg-white px-3 py-1.5 text-sm text-zinc-700 shadow-sm ring-1 ring-zinc-200 hover:bg-sky-50 hover:text-sky-700 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-700 dark:hover:bg-zinc-700">
                        {{ $nz['zip'] }} · {{ $nz['count'] }} {{ \Illuminate\Support\Str::plural('project', $nz['count']) }}
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Shared custom project ZIP map used across the site --}}
    <livewire:map-section :area="$area" />

    <x-cta-section
        variant="blue"
        heading="Remodeling in {{ $zip }}? Let's talk."
        description="Free in-home estimate with an itemized scope — permits, drawings, and inspections handled by us on every {{ $city }} project."
        primaryText="Get Free Quote"
        :primaryHref="route('contact')"
        secondaryText="View Our Work"
        :secondaryHref="route('projects.index')"
    />
</div>
