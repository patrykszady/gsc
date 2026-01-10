@php
    use App\Models\ProjectImage;

    $services = [
        [
            'slug' => 'kitchen-remodeling',
            'title' => 'Kitchen Remodeling',
            'projectType' => 'kitchen',
            'description' => 'Transform your kitchen into the heart of your home. From custom cabinetry and premium countertops to complete renovations – we create beautiful, functional spaces where families gather and memories are made.',
            'gradient' => 'from-sky-500 to-blue-600',
            'features' => [
                'Custom cabinetry & storage solutions',
                'Granite, quartz & marble countertops',
                'Flooring, lighting & complete renovations',
            ],
        ],
        [
            'slug' => 'bathroom-remodeling',
            'title' => 'Bathroom Remodeling',
            'projectType' => 'bathroom',
            'description' => 'Create your personal spa retreat with expert bathroom renovations. From luxurious walk-in showers and soaking tubs to modern vanities and tile work – we design bathrooms that combine comfort with style.',
            'gradient' => 'from-indigo-500 to-purple-600',
            'features' => [
                'Walk-in showers & luxury tubs',
                'Custom tile work & vanities',
                'Modern fixtures & lighting',
            ],
        ],
        [
            'slug' => 'home-remodeling',
            'title' => 'Home Remodeling',
            'projectType' => 'home-remodel',
            'description' => 'Comprehensive home renovations that breathe new life into your entire living space. From room additions and open floor plans to complete home makeovers – we handle projects of any scale with precision.',
            'gradient' => 'from-emerald-500 to-teal-600',
            'features' => [
                'Room additions & expansions',
                'Open concept floor plans',
                'Complete home renovations',
            ],
        ],
    ];

    // Helper to get cover image with thumbnail
    $getCoverImageData = function ($projectType) {
        $fallbacks = [
            'kitchen' => 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=1920&q=80',
            'bathroom' => 'https://images.unsplash.com/photo-1552321554-5fefe8c9ef14?w=1920&q=80',
            'home-remodel' => 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1920&q=80',
        ];
        $fallbackThumbs = [
            'kitchen' => 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=50&q=30',
            'bathroom' => 'https://images.unsplash.com/photo-1552321554-5fefe8c9ef14?w=50&q=30',
            'home-remodel' => 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=50&q=30',
        ];

        $image = ProjectImage::query()
            ->where('is_cover', true)
            ->whereHas('project', fn ($q) => $q->published()->ofType($projectType))
            ->inRandomOrder()
            ->first();

        if ($image) {
            return [
                'url' => $image->getWebpThumbnailUrl('medium') ?? $image->getThumbnailUrl('medium'),
                'thumb' => $image->getWebpThumbnailUrl('thumb') ?? $image->getThumbnailUrl('thumb'),
                'alt' => $image->seo_alt_text,
            ];
        }

        return [
            'url' => $fallbacks[$projectType] ?? $fallbacks['home-remodel'],
            'thumb' => $fallbackThumbs[$projectType] ?? $fallbackThumbs['home-remodel'],
            'alt' => ucfirst($projectType) . ' remodeling by GS Construction',
        ];
    };
@endphp

<section class="py-16 sm:py-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3 lg:gap-8">
            @foreach ($services as $service)
                @php $imageData = $getCoverImageData($service['projectType']); @endphp
                <div class="group relative overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-zinc-200 transition hover:shadow-xl dark:bg-zinc-800 dark:ring-zinc-700">
                    <div class="aspect-[16/9] overflow-hidden bg-gradient-to-br {{ $service['gradient'] }}">
                        <x-lqip-image 
                            :src="$imageData['url']"
                            :thumb="$imageData['thumb']"
                            :alt="$imageData['alt']"
                            class="h-full w-full transition duration-300 group-hover:scale-105"
                        />
                    </div>
                    <div class="p-6 lg:p-8">
                        <h2 class="text-xl font-bold text-zinc-900 lg:text-2xl dark:text-white">
                            {{ $service['title'] }}
                        </h2>
                        <p class="mt-3 text-sm leading-6 text-zinc-600 lg:mt-4 lg:text-base lg:leading-7 dark:text-zinc-400">
                            {{ $service['description'] }}
                        </p>
                        <ul class="mt-4 space-y-2 text-sm text-zinc-600 lg:mt-6 dark:text-zinc-400">
                            @foreach ($service['features'] as $feature)
                                <li class="flex items-start gap-2">
                                    <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                    </svg>
                                    <span>{{ $feature }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <div class="mt-6 lg:mt-8">
                            <a 
                                href="{{ isset($area) ? $area->pageUrl($service['slug']) : '/services/' . $service['slug'] }}" 
                                wire:navigate
                                class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-600 lg:px-6 lg:py-3"
                            >
                                Learn More
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- CTA Section --}}
<x-cta-section 
    variant="blue"
    heading="Ready to Start Your {{ isset($area) ? $area->city . ' ' : '' }}Project?"
    description="Get a free consultation and quote for your remodeling project. GS Construction is ready to bring your vision to life."
    primaryText="Get Free Quote"
    :primaryHref="isset($area) ? $area->pageUrl('contact') : route('contact')"
    secondaryText="View Our Work"
    :secondaryHref="isset($area) ? $area->pageUrl('projects') : route('projects.index')"
/>
