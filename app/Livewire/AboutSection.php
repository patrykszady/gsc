<?php

namespace App\Livewire;

use App\Models\AreaServed;
use Livewire\Component;

class AboutSection extends Component
{
    public ?AreaServed $area = null;
    public string $variant = 'default'; // 'default' or 'team'

    public function render()
    {
        $content = $this->getContent();
        
        return view('livewire.about-section', [
            'area' => $this->area,
            'content' => $content,
        ]);
    }

    protected function getContent(): array
    {
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
        
        return [
            'label' => 'About Us',
            'heading' => $city ? "Your Trusted {$city} Remodelers" : 'GS CONSTRUCTION & REMODELING',
            'intro' => '<strong class="font-semibold text-zinc-900 dark:text-white">GS Construction & Remodeling</strong> is a family affair, run by Gregory and Patryk, a dynamic <strong class="font-semibold text-zinc-900 dark:text-white">father-son duo</strong>.' . ($city ? " We're proud to serve {$city} homeowners, forming genuine connections with every family we work with." : " We're all about forming genuine connections with our homeowners."),
            'body' => 'We make sure you\'re comfortable with every decision we make together. With our keen eye for detail and top-notch tradesmen, we catch and address concerns early. Plus, we\'re always on-site, ensuring your project is smooth and stress-free.',
            'features' => [
                'Father-son team with combined 4 decades of experience',
                'On-site supervision for every project',
                'Transparent communication throughout',
                'Top-notch craftsmanship guaranteed',
            ],
            'quote' => 'Simply put, you\'re in good hands with us.',
            'cta_text' => 'Contact Gregory & Patryk',
            'cta_href' => '/contact',
        ];
    }
}
