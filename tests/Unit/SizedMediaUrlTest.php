<?php

namespace Tests\Unit;

use App\Services\GoogleBusinessProfileService as Gbp;
use PHPUnit\Framework\TestCase;

/**
 * A bare googleusercontent.com URL serves a 512px rendition, which made our
 * uploads look low-quality on the photo pages ("View on Google"), in the
 * ImageObject sameAs, and in the sitemap's <image:loc>. The originals were
 * always intact — only the size token was missing.
 */
class SizedMediaUrlTest extends TestCase
{
    public function test_appends_s0_so_google_returns_the_original_upload(): void
    {
        $bare = 'https://lh3.googleusercontent.com/LUh2qKvESp1v-BeG9KGO';

        $this->assertSame($bare . '=s0', Gbp::sizedMediaUrl($bare));
    }

    public function test_honours_an_explicit_size_token(): void
    {
        $this->assertSame(
            'https://lh3.googleusercontent.com/abc=w2400',
            Gbp::sizedMediaUrl('https://lh3.googleusercontent.com/abc', 'w2400'),
        );
    }

    public function test_leaves_an_already_sized_url_alone(): void
    {
        $sized = 'https://lh3.googleusercontent.com/abc=w1200-h800';

        $this->assertSame($sized, Gbp::sizedMediaUrl($sized));
    }

    public function test_passes_empty_and_null_through_as_null(): void
    {
        $this->assertNull(Gbp::sizedMediaUrl(null));
        $this->assertNull(Gbp::sizedMediaUrl(''));
        $this->assertNull(Gbp::sizedMediaUrl('   '));
    }
}
