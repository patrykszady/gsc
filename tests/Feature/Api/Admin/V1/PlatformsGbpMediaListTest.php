<?php

namespace Tests\Feature\Api\Admin\V1;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GET platforms/gbp/media — every media item of one listing (2026-09-22),
 * for the central admin's per-market photo pass-through to see what Google
 * already has. Same account/location shape as gbp/reviews and the existing
 * POST/DELETE gbp/media. Never a real Google call: Http::fake throughout.
 */
class PlatformsGbpMediaListTest extends TestCase
{
    private function headers(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    private function connect(): void
    {
        Cache::flush();
        config([
            'services.google.business_profile.refresh_token' => 'test-refresh-token',
            'services.google.business_profile.client_id' => 'client-id',
            'services.google.business_profile.client_secret' => 'client-secret',
        ]);
    }

    public function test_two_google_pages_are_merged_into_one_flat_list_of_five_keys(): void
    {
        $this->connect();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600], 200),
            'mybusiness.googleapis.com/v4/accounts/900/locations/111/media*' => Http::sequence()
                ->push([
                    'mediaItems' => [
                        [
                            'name' => 'accounts/900/locations/111/media/one',
                            'sourceUrl' => 'https://example.test/one.jpg',
                            'googleUrl' => 'https://lh3.googleusercontent.com/one',
                            'locationAssociation' => ['category' => 'ADDITIONAL'],
                            'createTime' => '2026-05-01T12:00:00Z',
                        ],
                    ],
                    'nextPageToken' => 'page-2',
                ])
                ->push([
                    'mediaItems' => [
                        [
                            'name' => 'accounts/900/locations/111/media/two',
                            'sourceUrl' => null,
                            'googleUrl' => 'https://lh3.googleusercontent.com/two',
                            'locationAssociation' => ['category' => 'EXTERIOR'],
                            'createTime' => '2026-05-02T12:00:00Z',
                        ],
                    ],
                    // No nextPageToken: this is the last page.
                ]),
        ]);

        $data = $this->getJson('/api/admin/v1/platforms/gbp/media?account_id=900&location_id=locations/111', $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['count']);
        $this->assertSame([
            [
                'name' => 'accounts/900/locations/111/media/one',
                'source_url' => 'https://example.test/one.jpg',
                'google_url' => 'https://lh3.googleusercontent.com/one',
                'category' => 'ADDITIONAL',
                'create_time' => '2026-05-01T12:00:00Z',
            ],
            [
                'name' => 'accounts/900/locations/111/media/two',
                'source_url' => null,
                'google_url' => 'https://lh3.googleusercontent.com/two',
                'category' => 'EXTERIOR',
                'create_time' => '2026-05-02T12:00:00Z',
            ],
        ], $data['items']);

        // Two requests: the first page, then the second requested with the
        // pageToken the first page returned.
        Http::assertSentCount(3); // + 1 OAuth token refresh
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'pageToken=page-2'));
    }

    public function test_media_list_is_refused_when_google_business_profile_is_not_connected(): void
    {
        config(['services.google.business_profile.refresh_token' => null]);

        $this->getJson('/api/admin/v1/platforms/gbp/media?account_id=900&location_id=111', $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Connect Google Business Profile first.');
    }

    public function test_a_google_error_is_reported_calmly_the_same_shape_delete_uses(): void
    {
        $this->connect();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600], 200),
            'mybusiness.googleapis.com/*' => Http::response('{"error":{"message":"internal error"}}', 500),
        ]);

        $this->getJson('/api/admin/v1/platforms/gbp/media?account_id=900&location_id=111', $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Google refused the media lookup: List media failed')
            ->assertJsonPath('errors.google.0', 'Google refused the media lookup: List media failed');
    }

    public function test_account_and_location_are_required(): void
    {
        $this->getJson('/api/admin/v1/platforms/gbp/media', $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account_id', 'location_id']);
    }

    public function test_a_leading_locations_prefix_is_stripped_before_calling_google(): void
    {
        $this->connect();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600], 200),
            'mybusiness.googleapis.com/*' => Http::response(['mediaItems' => []], 200),
        ]);

        $this->getJson('/api/admin/v1/platforms/gbp/media?account_id=accounts/900&location_id=locations/111', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/accounts/900/locations/111/media'));
    }

    public function test_never_reaches_real_google_endpoints(): void
    {
        $this->connect();
        Http::preventStrayRequests();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600], 200),
            'mybusiness.googleapis.com/*' => Http::response(['mediaItems' => []], 200),
        ]);

        $this->getJson('/api/admin/v1/platforms/gbp/media?account_id=900&location_id=111', $this->headers())
            ->assertOk();
    }
}
