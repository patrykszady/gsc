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

    /**
     * The warranty bullet, in one place.
     *
     * The written term is one year and that number lives on /warranty alone —
     * everywhere else we lead with the promise, because "1-year" reads as an
     * expiry date and the whole point of the page is that we still show up
     * after it. Five copies of this bullet had the number baked in.
     */
    protected function warrantyFeature(string $text): array
    {
        return [
            'text' => $text,
            'href' => '/warranty',
            'linkText' => 'our commitment never expires',
        ];
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
                'intro' => '<strong class="font-semibold text-zinc-900 dark:text-white">GS Construction & Remodeling</strong> is run by Gregory and Patryk, a <strong class="font-semibold text-zinc-900 dark:text-white">father-son team</strong> with more than 40 years between them. Greg built his name installing custom cabinetry in New York City; Patryk has worked alongside him for two decades.',
                'body' => 'One of them is on your job personally — the estimate, the site visits, the walkthrough. Your proposal is an itemized scope rather than a single number, permits are ours to pull and manage, and a private client portal shows your schedule, change orders and current balance while the work runs.',
                'features' => [
                    'Free in-home estimate from an owner, not a salesperson',
                    'Itemized scope — labor, materials, demolition and disposal, line by line',
                    'Permits pulled and managed with your village',
                    $this->warrantyFeature('Workmanship warranty, with the owners a phone call away'),
                ],
                'quote' => 'The owners are on your job — that is the whole difference.',
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
            'intro' => '<strong class="font-semibold text-zinc-900 dark:text-white">GS Construction & Remodeling</strong> is run by Gregory and Patryk, a <strong class="font-semibold text-zinc-900 dark:text-white">father-son team</strong> with more than 40 years between them, serving ' . ($city ? "{$city} " : 'Chicagoland ') . 'homeowners since 2015.',
            'body' => 'One of the owners is on your job personally. You get an itemized scope instead of a single mystery number, permits handled with your village, and a private client portal showing the schedule, any change orders and your current balance as the work runs.',
            'features' => [
                'Free in-home estimate from an owner, not a salesperson',
                'Itemized scope — labor, materials, demolition and disposal, line by line',
                'Permits pulled and managed with your village',
                $this->warrantyFeature('Workmanship warranty on every project'),
            ],
            'quote' => 'The owners are on your job — that is the whole difference.',
            'cta_text' => 'Contact Gregory & Patryk',
            'cta_href' => $ctaHref,
        ];
    }

    protected function getServiceContent(): array
    {
        $title = $this->serviceTitle;
        $shortTitle = $this->serviceShortTitle ?? $title;
        $city = $this->area?->city;
        $ctaHref = $this->area ? $this->area->pageUrl('contact') : '/contact';
        
        // Area-specific service content (e.g., "Bathroom Remodeling in Arlington Heights")
        if ($city) {
            // Copy drawn from what the site actually publishes on /process,
            // /warranty and config/sites/gsc/services-content.php — itemized
            // scope, permits handled, owner supervision, real build windows,
            // workmanship warranty. The previous version was unverifiable claims
            // ("top-notch craftsmanship guaranteed", "in good hands with us")
            // that said nothing a competitor could not also say.
            $serviceKeywords = [
                'Kitchen Remodeling' => [
                    'label' => "{$city} Kitchen Remodeling",
                    'intro' => "<strong class=\"font-semibold text-zinc-900 dark:text-white\">GS Construction & Remodeling</strong> has remodeled kitchens for {$city} homeowners since 2015. Gregory and Patryk are a <strong class=\"font-semibold text-zinc-900 dark:text-white\">father-son team</strong> with more than 40 years between them, and one of them is on your job personally.",
                    'body' => "A {$city} kitchen usually takes 8–12 weeks, and Greg or Patryk is here for those weeks — not a project manager passing your questions along. We pull the {$city} permits, bring in the trades we've worked with for years in the right order, and price every line of it before demo day.",
                    'features' => [
                        'Free in-home estimate from an owner, not a salesperson',
                        'Itemized scope — labor, materials, demolition and disposal, line by line',
                        "Building, plumbing and electrical permits pulled with {$city}",
                        $this->warrantyFeature('Workmanship warranty on every kitchen'),
                    ],
                    'quote' => "Typically 8–12 weeks on site, with an owner there daily.",
                ],
                'Bathroom Remodeling' => [
                    'label' => "{$city} Bathroom Remodeling",
                    'intro' => "<strong class=\"font-semibold text-zinc-900 dark:text-white\">GS Construction & Remodeling</strong> has remodeled bathrooms for {$city} homeowners since 2015. Gregory and Patryk are a <strong class=\"font-semibold text-zinc-900 dark:text-white\">father-son team</strong> with more than 40 years between them, and one of them is on your job personally.",
                    'body' => "A {$city} bathroom usually takes 3–5 weeks. The parts that matter are the ones you never see again once the walls close, so one of us is here while it happens and the village signs off before anything gets covered. You get the scope written out line by line up front, not one number you can't check.",
                    'features' => [
                        'Free in-home estimate from an owner, not a salesperson',
                        'Waterproofing and shower pans built to spec, not to schedule',
                        "Plumbing and electrical permits pulled with {$city}",
                        $this->warrantyFeature('Workmanship warranty on every bathroom'),
                    ],
                    'quote' => "Typically 3–5 weeks on site, with an owner there daily.",
                ],
                'Home Remodeling' => [
                    'label' => "{$city} Home Remodeling",
                    'intro' => "<strong class=\"font-semibold text-zinc-900 dark:text-white\">GS Construction & Remodeling</strong> has remodeled homes for {$city} homeowners since 2015. Gregory and Patryk are a <strong class=\"font-semibold text-zinc-900 dark:text-white\">father-son team</strong> with more than 40 years between them, and one of them is on your job personally.",
                    'body' => "Whole-home work lives or dies on the order things happen in. We run it as one job with one schedule and one number to call, with an owner here on site and a portal showing you the schedule, any change orders and your balance — so the trades aren't tripping over each other and you aren't running your own remodel.",
                    'features' => [
                        'Free in-home estimate from an owner, not a salesperson',
                        'One itemized scope and one schedule across every room',
                        "Permits and inspections handled with {$city} across every trade",
                        $this->warrantyFeature('Workmanship warranty on the whole project'),
                    ],
                    'quote' => "One scope, one schedule, one point of contact.",
                ],
            ];
            
            $serviceData = $serviceKeywords[$title] ?? null;
            
            if ($serviceData) {
                return [
                    'label' => $serviceData['label'],
                    'heading' => 'GS CONSTRUCTION & REMODELING',
                    'intro' => $serviceData['intro'],
                    'body' => $serviceData['body'],
                    'features' => $serviceData['features'],
                    'quote' => $serviceData['quote'],
                    'cta_text' => 'Contact Gregory & Patryk',
                    'cta_href' => $ctaHref,
                ];
            }
        }
        
        // Fallback for non-area service pages
        return [
            'label' => $shortTitle . ' Experts',
            'heading' => "Expert {$shortTitle} Services",
            'intro' => "<strong class=\"font-semibold text-zinc-900 dark:text-white\">GS Construction & Remodeling</strong> specializes in professional {$title}. As a <strong class=\"font-semibold text-zinc-900 dark:text-white\">father-son team</strong>, we bring decades of combined experience to every project.",
            'body' => "From the first walkthrough to the last punch-list item, Greg or Patryk runs your {$shortTitle} project themselves. That means a scope written out line by line instead of one number, permits pulled and handled with your village, and the same faces on site rather than a crew you've never met.",
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
