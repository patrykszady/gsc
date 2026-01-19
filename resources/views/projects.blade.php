<x-layouts.app
    title="Remodeling Projects"
    metaDescription="Browse our portfolio of kitchen, bathroom, and home remodeling projects. See the quality craftsmanship of GS Construction in the Chicagoland area."
>
    {{-- Breadcrumb Schema --}}
    <x-breadcrumb-schema :items="[
        ['name' => 'Projects'],
    ]" />

    {{-- Visual Breadcrumb --}}
    <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">Projects</span>
                </li>
            </ol>
        </nav>
    </div>

    {{-- Timelapse Section --}}
    <livewire:timelapse-section />

    {{-- Projects Grid --}}
    <livewire:projects-grid />

    {{-- CTA Section --}}
    <div class="relative isolate z-0 bg-white px-6 py-12 sm:py-16 lg:px-8 dark:bg-zinc-900">
        <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-1/2 -z-10 -translate-y-1/2 transform-gpu overflow-hidden opacity-20 blur-3xl">
            <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[max(50%,38rem)] aspect-[1313/771] w-[82.0625rem] bg-linear-to-tr from-sky-300 to-sky-600"></div>
        </div>
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="font-heading text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
                Ready to Start Your Project?
            </h2>
            <p class="mx-auto mt-4 max-w-xl text-lg text-zinc-600 dark:text-zinc-300">
                Let's discuss your vision. Schedule a free consultation with Greg & Patryk.
            </p>
            <div class="mt-8 flex items-center justify-center gap-x-6">
                <flux:button href="/contact" variant="primary" class="font-semibold uppercase tracking-wide" @click="trackCTA('Schedule Free Consultation', 'projects_page_cta')">
                    Schedule Free Consultation
                </flux:button>
                <a href="/about" class="text-sm/6 font-semibold text-zinc-900 dark:text-white" @click="trackCTA('About Us', 'projects_page_secondary')">
                    About Us <span aria-hidden="true">→</span>
                </a>
            </div>
        </div>
    </div>
</x-layouts.app>
