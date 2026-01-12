<?php

namespace App\Livewire;

use App\Models\AreaServed;
use Livewire\Component;

class AboutSection extends Component
{
    public ?AreaServed $area = null;
    public string $variant = 'default'; // 'default', 'team', or 'service'
    public ?string $projectType = null;
    public ?string $serviceTitle = null;
    public ?string $serviceShortTitle = null;

    public function placeholder(): string
    {
        return <<<'HTML'
        <section class="overflow-hidden bg-zinc-50 py-8 sm:py-10 dark:bg-slate-950">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mx-auto grid max-w-2xl grid-cols-1 gap-x-12 gap-y-8 lg:mx-0 lg:max-w-none lg:grid-cols-2 lg:items-start">
                    <div class="lg:pr-8">
                        <div class="lg:max-w-lg space-y-4">
                            <div class="h-4 w-24 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                            <div class="h-8 w-3/4 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                            <div class="h-20 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                            <div class="space-y-3">
                                <div class="h-5 w-full bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                                <div class="h-5 w-5/6 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                                <div class="h-5 w-4/5 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                            </div>
                        </div>
                    </div>
                    <div class="lg:mt-[4.5rem] lg:pl-4">
                        <div class="aspect-[4/3] w-full bg-zinc-200 dark:bg-zinc-700 rounded-xl animate-pulse"></div>
                        <div class="mt-4 h-16 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                    </div>
                </div>
            </div>
        </section>
        HTML;
    }

    public function render()
    {
        $content = $this->getContent();
        
        return view('livewire.about-section', [
            'content' => $content,
        ]);
    }

    protected function getContent(): array
    {
        if ($this->variant === 'service' && $this->serviceTitle) {
            return $this->getServiceContent();
        }

        if ($this->variant === 'team') {
            return [
                'label' => 'Meet the Team',
                'heading' => 'Gregory & Patryk',
                'intro' => '<strong class="font-semibold text-zinc-900 dark:text-white">GS Construction & Remodeling</strong> is a family affair, run by Gregory and Patryk, a dynamic <strong class="font-semibold text-zinc-900 dark:text-white">father-son duo</strong>. We\'re all about forming genuine connections with our homeowners.',
                'body' => 'We make sure you\'re comfortable with every decision we make together. With our keen eye for detail and top-notch tradesmen, we catch and address concerns early. Plus, we\'re always on-site, ensuring your project is smooth and stress-free.',
                'features' => [
                    'Father-son team with combined 4 decades of experience',
                    'On-site supervision for every project',
                    'Transparent communication throughout',
                    'Top-notch craftsmanship guaranteed',
                ],
                'quote' => 'Simply put, you\'re in good hands with us.',
                'cta_text' => 'Schedule Free Consultation',
                'cta_href' => '/#contact',
            ];
        }

        // Default content (home page)
        $city = $this->area?->city;
        $cityLabel = $city ? "Your {$city} " : '';
        $ctaHref = $this->area ? $this->area->pageUrl('contact') : '/contact';
        
        return [
            'label' => $city ? "Serving {$city}" : 'About Us',
            'heading' => 'GS CONSTRUCTION & REMODELING',
            'intro' => '<strong class="font-semibold text-zinc-900 dark:text-white">GS Construction & Remodeling</strong> is a family affair, run by Gregory and Patryk, a dynamic <strong class="font-semibold text-zinc-900 dark:text-white">father-son duo</strong>. We\'re all about forming genuine connections with ' . ($city ? "{$city} " : '') . 'homeowners.',
            'body' => 'We make sure you\'re comfortable with every decision we make together. With our keen eye for detail and top-notch tradesmen, we catch and address concerns early. Plus, we\'re always on-site, ensuring your project is smooth and stress-free.',
            'features' => [
                'Father-son team with combined 4 decades of experience',
                'On-site supervision for every project',
                'Transparent communication throughout',
                'Top-notch craftsmanship guaranteed',
            ],
            'quote' => 'Simply put, you\'re in good hands with us.',
            'cta_text' => 'Contact Gregory & Patryk',
            'cta_href' => $ctaHref,
        ];
    }

    protected function getServiceContent(): array
    {
        $title = $this->serviceTitle;
        $shortTitle = $this->serviceShortTitle ?? $title;
        
        return [
            'label' => $shortTitle . ' Experts',
            'heading' => "Expert {$shortTitle} Services",
            'intro' => "<strong class=\"font-semibold text-zinc-900 dark:text-white\">GS Construction & Remodeling</strong> specializes in professional {$title}. As a <strong class=\"font-semibold text-zinc-900 dark:text-white\">father-son team</strong>, we bring decades of combined experience to every project.",
            'body' => "From initial design consultation to final walkthrough, we handle every aspect of your {$shortTitle} project with care. Our hands-on approach means you'll always have Gregory or Patryk on-site, ensuring quality at every step.",
            'features' => [
                "Specialized {$shortTitle} expertise",
                'On-site supervision for every project',
                'Transparent pricing with no surprises',
                'Quality craftsmanship guaranteed',
            ],
            'quote' => "Your {$shortTitle} project deserves the best—we deliver it.",
            'cta_text' => 'Get a Free Quote',
            'cta_href' => '/contact',
        ];
    }
}
