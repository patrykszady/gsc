<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\ReviewUrl;
use App\Services\GoogleBusinessProfileService;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * GET platforms/gbp/reviews hands the central admin one listing's Google
 * reviews (2026-09-22) so it can import them as testimonials per market,
 * and the testimonials API keeps the review id on the link so the next
 * lookup can say which are already here. Google is never called — the
 * service is mocked, as in PlatformsGbpListingsTest.
 */
class PlatformsGbpReviewsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.admin_api.token' => 'test-admin-api-token']);

        $this->mock(GoogleBusinessProfileService::class, function (MockInterface $mock) {
            $mock->shouldReceive('hasRefreshToken')->andReturn(true);
            $mock->shouldReceive('getLastError')->andReturn(null);
            $mock->shouldReceive('fetchReviewsFor')->with('900', '111', null)->andReturn([
                'reviews' => [
                    ['name' => 'accounts/900/locations/111/reviews/r1', 'reviewer' => ['displayName' => 'Dana K.'], 'starRating' => 'FIVE',
                        'comment' => 'Great crew.', 'createTime' => '2026-08-01T15:00:00Z'],
                    ['name' => 'accounts/900/locations/111/reviews/r2', 'reviewer' => ['displayName' => 'Sam O.'], 'starRating' => 'THREE',
                        'createTime' => '2026-08-10T15:00:00Z'],
                ],
                'totalReviewCount' => 2,
                'averageRating' => 4.0,
                'nextPageToken' => 'next',
            ]);
        });
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    public function test_reviews_are_shaped_for_the_import_and_an_imported_one_is_flagged(): void
    {
        // Import the first through the testimonials API, id and all.
        $this->postJson('/api/admin/v1/testimonials', [
            'reviewer_name' => 'Dana K.',
            'review_description' => 'Great crew.',
            'star_rating' => 5,
            'review_urls' => [['platform' => 'google', 'url' => 'https://www.google.com/maps/reviews?reviewid=r1', 'external_id' => 'r1']],
        ], $this->headers())->assertCreated();

        $this->assertSame('r1', ReviewUrl::where('platform', 'google')->firstOrFail()->external_id);

        $data = $this->getJson('/api/admin/v1/platforms/gbp/reviews?account_id=900&location_id=locations/111', $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertSame('next', $data['next_page_token']);
        $this->assertSame(2, $data['total_review_count']);
        $this->assertSame([true, false], array_column($data['reviews'], 'imported'));
        $this->assertSame([5, 3], array_column($data['reviews'], 'rating'));
        $this->assertSame('https://www.google.com/maps/reviews?reviewid=r2', $data['reviews'][1]['url']);
    }
}
