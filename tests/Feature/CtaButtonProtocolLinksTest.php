<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * wire:navigate belongs on page navigations only.
 *
 * <x-buttons.cta> stamped it on every link it rendered. Livewire intercepts the
 * click and fetches the href as a page, so tapping "Call (224) 735-4200" —
 * href="tel:2247354200" — SPA-navigated to a literal /tel:… URL and landed on
 * the 404 page instead of opening the dialer. The same applies to mailto:, sms:
 * and in-page anchors.
 */
class CtaButtonProtocolLinksTest extends TestCase
{
    /** @return array<int, array{0: string}> */
    public static function nonNavigableHrefs(): array
    {
        return [
            ['tel:2247354200'],
            ['mailto:crew@gs.construction'],
            ['sms:2247354200'],
            ['#estimate'],
        ];
    }

    #[DataProvider('nonNavigableHrefs')]
    public function test_protocol_links_are_not_intercepted_by_livewire(string $href): void
    {
        $html = Blade::render('<x-buttons.cta href="'.$href.'">Call</x-buttons.cta>');

        $this->assertStringContainsString('href="'.$href.'"', $html);
        $this->assertStringNotContainsString(
            'wire:navigate',
            $html,
            $href.' would be fetched as a page instead of handed to the OS',
        );
    }

    public function test_real_page_links_still_navigate(): void
    {
        $html = Blade::render('<x-buttons.cta href="/contact">Estimate</x-buttons.cta>');

        $this->assertStringContainsString('wire:navigate', $html);
    }

    public function test_the_call_cta_on_an_alternative_page_does_not_navigate(): void
    {
        $html = $this->get('/compare/4ever-remodeling')->assertOk()->getContent();

        preg_match_all('#<a[^>]*href="tel:[^"]*"[^>]*>#s', $html, $links);
        $this->assertNotEmpty($links[0], 'the Call CTA went missing');

        foreach ($links[0] as $tag) {
            $this->assertStringNotContainsString('wire:navigate', $tag);
        }
    }
}
