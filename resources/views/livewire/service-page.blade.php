<div>
    {{-- Breadcrumb Schema --}}
    <x-breadcrumb-schema :items="[
        ['name' => 'Services', 'url' => route('services.index')],
        ['name' => $data['title']],
    ]" />

    {{-- Service Schema --}}
    <x-service-schema :service="$data" />

    {{-- Hero Section --}}
    <livewire:main-project-hero-slider 
        :project-type="$data['projectType']"
        :slides="[
            [
                'heading' => $data['heroTitle'],
                'subheading' => $data['heroSubtitle'],
                'type' => $data['projectType'],
            ],
        ]"
        primary-cta-text="Get a Free Quote"
        primary-cta-url="/contact"
        secondary-cta-text="View Our Work"
        secondary-cta-url="/projects"
    />

    {{-- About Section --}}
    {{-- <section class="py-16 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl text-center">
                <h2 class="text-3xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-4xl">
                    Expert {{ $data['title'] }} Services
                </h2>
                <p class="mt-6 text-lg leading-8 text-zinc-600 dark:text-zinc-400">
                    {{ $data['description'] }}
                </p>
            </div>
        </div>
    </section> --}}

    {{-- Features Section --}}
    {{-- <section class="bg-zinc-50 py-16 sm:py-24 dark:bg-zinc-800/50">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-3xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-4xl">
                    What We Offer
                </h2>
            </div>
            <div class="mt-12 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                @foreach($data['features'] as $feature)
                    <div class="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-700">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $feature['title'] }}</h3>
                        <p class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">{{ $feature['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section> --}}

    {{-- Process Section --}}
    {{-- <section class="py-16 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-3xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-4xl">
                    Our Process
                </h2>
                <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-400">
                    From initial consultation to final walkthrough, here's what to expect.
                </p>
            </div>
            <div class="mt-12 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                @foreach($data['process'] as $step)
                    <div class="relative">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-sky-600 text-xl font-bold text-white">
                            {{ $step['step'] }}
                        </div>
                        <h3 class="mt-4 text-lg font-semibold text-zinc-900 dark:text-white">{{ $step['title'] }}</h3>
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $step['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section> --}}

    {{-- Projects Section --}}
    @if($projects->isNotEmpty())
        <livewire:projects-grid :projectType="$data['projectType']" :limit="3" :hideFilters="true" />
    @endif

    {{-- Testimonials Section --}}
    <livewire:testimonials-section :project-type="$data['projectType']" :key="'testimonials-'.$data['projectType']" />

    {{-- Internal Links Section --}}
    <x-internal-links :projects="$projects" :current-service="$service" />

    {{-- CTA Section --}}
    <x-cta-section 
        variant="blue"
        :heading="$data['ctaHeading']"
        description="Get a free consultation and quote for your remodeling project. GS Construction is ready to bring your vision to life."
        primaryText="Get Free Quote"
        :primaryHref="route('contact')"
        secondaryText="View Our Work"
        :secondaryHref="route('projects.index')"
    />
</div>
