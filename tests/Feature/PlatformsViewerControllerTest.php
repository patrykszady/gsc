<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The signed, unauthenticated noVNC viewer redirect (routes/platforms-viewer.php
 * + PlatformsViewerController) that PlatformsController::remoteLoginPayload()
 * mints a URL for.
 *
 * SAFETY: minting a signed URL and asserting it validates/redirects is
 * explicitly safe per the task's HARD SAFETY RULES — this never follows the
 * redirect to a real noVNC endpoint, only asserts the Location header our own
 * controller produces from a harmless, test-supplied cache value.
 */
class PlatformsViewerControllerTest extends TestCase
{
    protected function mintUrl(string $provider = 'yelp', int $ttlMinutes = 15): string
    {
        return URL::temporarySignedRoute(
            'admin.platforms.viewer',
            now()->addMinutes($ttlMinutes),
            ['site' => 'gs.construction', 'provider' => $provider],
        );
    }

    public function test_a_freshly_minted_signature_is_valid(): void
    {
        $this->assertTrue(URL::hasValidSignature(request()->create($this->mintUrl())));
    }

    public function test_it_rejects_a_request_with_no_signature(): void
    {
        $this->get('/admin/gs.construction/platforms/yelp/viewer')->assertStatus(403);
    }

    public function test_it_rejects_a_tampered_signature(): void
    {
        $url = $this->mintUrl().'&tampered=1';

        $this->get($url)->assertStatus(403);
    }

    public function test_it_rejects_an_expired_signature(): void
    {
        $url = $this->mintUrl(ttlMinutes: 1);

        Carbon::setTestNow(now()->addMinutes(2));
        try {
            $this->get($url)->assertStatus(403);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_returns_410_when_no_remote_login_session_is_cached(): void
    {
        Cache::forget('platforms.remote_login_url.yelp');

        $this->get($this->mintUrl('yelp'))->assertStatus(410);
    }

    /**
     * Redirects to whatever URL PlatformsController cached for this
     * provider — never opened here, just asserted as the Location header.
     */
    public function test_it_redirects_to_the_cached_viewer_url_without_a_gsc_session(): void
    {
        Cache::put('platforms.remote_login_url.yelp', 'http://127.0.0.1:6080/vnc.html?password=abc', now()->addMinutes(5));

        // Deliberately no actingAs() / auth — this is the whole point: the
        // route must work for a signed, unauthenticated request.
        $this->get($this->mintUrl('yelp'))
            ->assertRedirect('http://127.0.0.1:6080/vnc.html?password=abc');
    }

    public function test_it_redirects_for_instagram_independently_of_yelp(): void
    {
        Cache::put('platforms.remote_login_url.yelp', 'http://example.test/yelp-vnc.html', now()->addMinutes(5));
        Cache::put('platforms.remote_login_url.instagram', 'http://example.test/ig-vnc.html', now()->addMinutes(5));

        $this->get($this->mintUrl('instagram'))
            ->assertRedirect('http://example.test/ig-vnc.html');
    }

    public function test_it_rejects_an_unknown_provider(): void
    {
        $url = URL::temporarySignedRoute(
            'admin.platforms.viewer',
            now()->addMinutes(15),
            ['site' => 'gs.construction', 'provider' => 'bogus'],
        );

        $this->get($url)->assertStatus(404);
    }
}
