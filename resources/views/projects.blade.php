<x-layouts.app
    title="Remodeling Projects"
    metaDescription="Browse our portfolio of kitchen, bathroom, and home remodeling projects. See the quality craftsmanship of GS Construction in the Chicagoland area."
>
    {{-- Breadcrumb Schema --}}
    @php
        // On /projects/{type} filter pages, deepen the trail to
        // Home › Projects › {Type} so Google can render a category breadcrumb
        // (the route merges the internal type into request('type')).
        $projectTypeCrumb = [
            'kitchen'      => ['label' => 'Kitchens',        'slug' => 'kitchens'],
            'bathroom'     => ['label' => 'Bathrooms',       'slug' => 'bathrooms'],
            'home-remodel' => ['label' => 'Home Remodeling', 'slug' => 'home-remodeling'],
        ];
        $activeType = request('type');
        $activeCrumb = $projectTypeCrumb[$activeType] ?? null;

        $projectsCrumbs = [];
        if ($activeCrumb) {
            $projectsCrumbs[] = ['name' => 'Projects', 'url' => route('projects.index')];
            $projectsCrumbs[] = ['name' => $activeCrumb['label']];
        } else {
            $projectsCrumbs[] = ['name' => 'Projects'];
        }
    @endphp
    <x-breadcrumb-schema :items="$projectsCrumbs" />

    {{-- ItemList (summary-page carousel) of the projects in this portfolio view.
         Scoped to the active /projects/{type} filter when present. Each ListItem
         points at a /projects/{slug} detail page, which carries full Article +
         ImageObject markup — making the portfolio eligible for Google carousel
         treatment. Ordered deterministically (featured first, then newest) so the
         markup is stable across the grid's randomized, paginated card order. --}}
    @php
        $projectListQuery = \App\Models\Project::query()
            ->where('is_published', true)
            ->when($activeType, fn ($q) => $q->where('project_type', $activeType))
            ->orderByDesc('is_featured')
            ->orderByDesc('completed_at')
            ->limit(30);
        $projectListRows = $projectListQuery->get(['slug', 'title']);

        if ($projectListRows->isNotEmpty()) {
            $projectListItems = [];
            foreach ($projectListRows as $i => $projectRow) {
                $projectListItems[] = [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'url'      => url('/projects/' . $projectRow->slug),
                    'name'     => $projectRow->title,
                ];
            }
            $projectItemList = [
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                '@id'             => url()->current() . '#project-list',
                'name'            => ($activeCrumb['label'] ?? 'Remodeling') . ' Projects — GS Construction',
                'itemListOrder'   => 'https://schema.org/ItemListOrderDescending',
                'numberOfItems'   => count($projectListItems),
                'itemListElement' => $projectListItems,
            ];
        }
    @endphp
    @if(!empty($projectItemList))
        <script type="application/ld+json">{!! json_encode($projectItemList, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif

    {{-- Visual Breadcrumb --}}
    <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    @if($activeCrumb)
                        <a href="{{ route('projects.index') }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Projects</a>
                    @else
                        <span class="ml-2 text-gray-700 dark:text-gray-300">Projects</span>
                    @endif
                </li>
                @if($activeCrumb)
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">{{ $activeCrumb['label'] }}</span>
                </li>
                @endif
            </ol>
        </nav>
    </div>

    {{-- Projects Grid (includes timelapse + filters) --}}
    <livewire:projects-grid :mobilePerPage="3" />

    {{-- CTA Section --}}
    <div class="mx-auto max-w-7xl px-4 pt-2 pb-2 sm:px-6 sm:pt-3 sm:pb-2 lg:px-8 lg:pt-4 lg:pb-2">
        <div class="overflow-hidden rounded-2xl shadow-sm">
            <x-cta-section
                variant="blue"
                heading="Ready to Start Your Project?"
                description="Get a free consultation and quote for your remodeling project. We're ready to bring your vision to life."
                primaryText="Get a Free Quote"
                primaryHref="/contact"
                secondaryText="View All Projects"
                secondaryHref="/projects"
            />
        </div>
    </div>

    {{-- FAQ Section --}}
    @php
        $faqs = [
            ['question' => 'What types of remodeling projects do you do?', 'answer' => 'GS Construction specializes in kitchen remodeling, bathroom remodeling, and whole-home renovations. We handle everything from single-room updates to complete home transformations across the Chicagoland area.'],
            ['question' => 'How do I get a free estimate for my project?', 'answer' => 'Contact us by phone at (224) 735-4200 or through our website to schedule a free in-home consultation. We will assess your space, discuss your vision, and provide a detailed, no-obligation estimate.'],
            ['question' => 'How long does a typical remodeling project take?', 'answer' => 'Timelines vary depending on scope — a bathroom remodel may take 2–6 weeks, a kitchen remodel 4–10 weeks, and larger whole-home renovations several months. We provide a detailed schedule before work begins.'],
            ['question' => 'Do you handle permits and inspections?', 'answer' => 'Yes, GS Construction handles all required permits and coordinates inspections for every project. We are familiar with building codes across Chicagoland and ensure full compliance.'],
            ['question' => 'Are you licensed, bonded, and insured?', 'answer' => 'Yes, GS Construction is fully licensed, bonded, and insured. We carry general liability insurance and workers\' compensation coverage for your protection.'],
        ];
    @endphp
    <x-faq-section
        :faqs="$faqs"
        heading="Remodeling Projects FAQ"
        sectionClasses="bg-white pt-1 pb-6 sm:pt-2 sm:pb-8 dark:bg-zinc-900"
    />
</x-layouts.app>
