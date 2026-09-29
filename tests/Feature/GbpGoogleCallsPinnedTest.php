<?php

namespace Tests\Feature;

use App\Models\ImagePlatformUpload;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\SeoAction;
use App\Services\GoogleBusinessProfileService;
use App\Services\Seo\Appliers\GbpDescriptionApplier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Every Google call gs.construction's Business Profile code makes, pinned
 * at the wire: URL, method, headers that matter and the exact body, in
 * order — the autopilot's profile writes (description, categories, service
 * area, service items), the site's own posting and reviews, and the
 * central admin's photo and review pass-throughs.
 *
 * Written against the pre-0.14 service and kept green across the move onto
 * the kit's one Business Profile client (0.14.0, "one Google"): the plumbing
 * changed, what Google receives did not. Google itself is never reached —
 * every host is faked and anything unfaked is refused.
 */
class GbpGoogleCallsPinnedTest extends TestCase
{
    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const V4 = 'https://mybusiness.googleapis.com/v4';

    private const INFO = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    private const PLACES = 'https://places.googleapis.com/v1/places:searchText';

    /** The one token refresh every run starts with, as Google receives it. */
    private const REFRESH = ['POST', self::TOKEN, [
        'client_id' => 'shared-client.apps.googleusercontent.com',
        'client_secret' => 'shared-secret',
        'refresh_token' => 'env-refresh-token',
        'grant_type' => 'refresh_token',
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // gs.construction as production runs it: the shared client, the
        // server-held grant and listing for the default site, photos owned
        // by the central admin (the site's own uploader inert).
        config([
            'services.google.business_profile.client_id' => 'shared-client.apps.googleusercontent.com',
            'services.google.business_profile.client_secret' => 'shared-secret',
            'services.google.business_profile.refresh_token' => 'env-refresh-token',
            'services.google.business_profile.account_id' => '111',
            'services.google.business_profile.location_id' => '222',
            'services.google.business_profile.photos_owned_by' => 'ss-systems',
            'services.google.business_profile.production_url' => 'https://gs.construction',
            'services.google.places_api_key' => 'places-key',
            'services.admin_api.token' => 'test-admin-api-token',
        ]);
    }

    /** @param  array<string, mixed>  $routes  URL pattern => response (or closure) */
    private function fakeGoogle(array $routes = []): void
    {
        Http::fake($routes + [
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'at-1',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/business.manage openid email',
            ]),
        ]);
        Http::preventStrayRequests();
    }

    /**
     * Every request sent, as [method, url, body]: the form or JSON body
     * decoded, [] for a bodiless GET/DELETE.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function sent(): array
    {
        return Http::recorded()->map(function (array $pair) {
            /** @var Request $request */
            [$request] = $pair;

            return [$request->method(), $request->url(), in_array($request->method(), ['GET', 'DELETE'], true) ? [] : $request->data()];
        })->values()->all();
    }

    /** Every Business Profile call carries the refreshed access token. */
    private function assertBearerOnGoogleApiCalls(): void
    {
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'googleapis.com') && ! str_starts_with($request->url(), self::TOKEN) && ! str_starts_with($request->url(), 'https://places.googleapis.com')) {
                $this->assertSame(['Bearer at-1'], $request->header('Authorization'), $request->url());
            }
        }
    }

    private function adminHeaders(): array
    {
        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    /** A published project photo with a real JPEG on the fake disk, created without observers (no AI, no uploads). */
    private function projectImage(): ProjectImage
    {
        $project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Kitchen Remodel',
            'slug' => 'kitchen-remodel-'.uniqid(),
            'project_type' => 'kitchen',
            'location' => 'Palatine, IL',
            'completed_at' => '2020-06-15',
            'is_published' => true,
        ]));

        $path = 'projects/kitchen-'.uniqid().'.jpg';
        Storage::disk('public')->put($path, (string) (new ImageManager(new Driver))->create(40, 30)->fill('#3366ff')->toJpeg()->toString());

        return ProjectImage::withoutEvents(fn () => ProjectImage::create([
            'project_id' => $project->id,
            'filename' => basename($path),
            'original_filename' => basename($path),
            'path' => $path,
            'alt_text' => 'Renovated kitchen with white cabinets',
            'caption' => 'A finished kitchen remodel.',
        ]));
    }

    /* ------------------------------------------------------------------ */
    /*  The autopilot's profile writes                                     */
    /* ------------------------------------------------------------------ */

    public function test_the_autopilot_description_apply_and_revert_read_and_patch_the_same_way(): void
    {
        $this->fakeGoogle([
            self::INFO.'/locations/222?readMask=profile' => Http::response(['profile' => ['description' => 'Old description.']]),
            self::INFO.'/locations/222?updateMask=profile.description' => Http::response(['name' => 'locations/222']),
        ]);

        $action = SeoAction::create(['fingerprint' => 'g1', 'source' => 'gbp', 'category' => 'gbp_description', 'risk' => 'review', 'status' => 'proposed', 'target_url' => 'https://gs.construction/', 'title' => 't', 'hypothesis' => 'h', 'metric' => 'impressions', 'payload' => ['new_description' => 'New keyword-led description.']]);

        $applier = new GbpDescriptionApplier;
        $applier->apply($action);
        $applier->revert($action);

        $this->assertSame('Old description.', $action->payload['prev_description']);
        $this->assertSame([
            self::REFRESH,
            ['GET', self::INFO.'/locations/222?readMask=profile', []],
            ['PATCH', self::INFO.'/locations/222?updateMask=profile.description', ['profile' => ['description' => 'New keyword-led description.']]],
            ['PATCH', self::INFO.'/locations/222?updateMask=profile.description', ['profile' => ['description' => 'Old description.']]],
        ], $this->sent());
        $this->assertBearerOnGoogleApiCalls();
    }

    public function test_a_refused_description_fails_the_action_with_the_same_message(): void
    {
        $this->fakeGoogle([
            self::INFO.'/locations/222?readMask=profile' => Http::response(['profile' => ['description' => 'Old description.']]),
            self::INFO.'/locations/222?updateMask=profile.description' => Http::response(['error' => ['code' => 400, 'message' => 'Description contains a URL.', 'status' => 'INVALID_ARGUMENT']], 400),
        ]);

        $action = SeoAction::create(['fingerprint' => 'g2', 'source' => 'gbp', 'category' => 'gbp_description', 'risk' => 'review', 'status' => 'proposed', 'target_url' => 'https://gs.construction/', 'title' => 't', 'hypothesis' => 'h', 'metric' => 'impressions', 'payload' => ['new_description' => 'Visit https://gs.construction.']]);

        try {
            (new GbpDescriptionApplier)->apply($action);
            $this->fail('A refused description must fail the action.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Google rejected the description: {"message":"Update description failed"', $e->getMessage());
            $this->assertStringContainsString('"status":400', $e->getMessage());
        }
    }

    /**
     * Before kit 0.14 a Google that did not answer threw out of the read; the
     * kit's client returns null instead. A description that could not be read
     * is not "no description": applying anyway would store null for revert,
     * and the revert would then blank the live listing.
     */
    public function test_a_description_that_cannot_be_read_is_never_overwritten(): void
    {
        $this->fakeGoogle([
            self::INFO.'/locations/222?readMask=profile' => Http::failedConnection('timed out'),
        ]);

        $action = SeoAction::create(['fingerprint' => 'g3', 'source' => 'gbp', 'category' => 'gbp_description', 'risk' => 'review', 'status' => 'proposed', 'target_url' => 'https://gs.construction/', 'title' => 't', 'hypothesis' => 'h', 'metric' => 'impressions', 'payload' => ['new_description' => 'New keyword-led description.']]);

        try {
            (new GbpDescriptionApplier)->apply($action);
            $this->fail('An unreadable description must fail the action.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Could not read the current description to keep for revert: {"message":"Google did not answer"', $e->getMessage());
        }

        $this->assertArrayNotHasKey('prev_description', $action->payload);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH');
    }

    public function test_an_empty_description_is_still_kept_for_revert_as_empty(): void
    {
        $this->fakeGoogle([
            self::INFO.'/locations/222?readMask=profile' => Http::response([]),
            self::INFO.'/locations/222?updateMask=profile.description' => Http::response(['name' => 'locations/222']),
        ]);

        $action = SeoAction::create(['fingerprint' => 'g4', 'source' => 'gbp', 'category' => 'gbp_description', 'risk' => 'review', 'status' => 'proposed', 'target_url' => 'https://gs.construction/', 'title' => 't', 'hypothesis' => 'h', 'metric' => 'impressions', 'payload' => ['new_description' => 'New keyword-led description.']]);

        (new GbpDescriptionApplier)->apply($action);

        $this->assertArrayHasKey('prev_description', $action->payload);
        $this->assertNull($action->payload['prev_description']);
    }

    /** A revert Google did not take is never recorded as reverted. */
    public function test_a_revert_google_refuses_fails_loudly(): void
    {
        $this->fakeGoogle([
            self::INFO.'/locations/222?updateMask=profile.description' => Http::response(['error' => ['code' => 503, 'message' => 'Backend error', 'status' => 'UNAVAILABLE']], 503),
        ]);

        $action = SeoAction::create(['fingerprint' => 'g5', 'source' => 'gbp', 'category' => 'gbp_description', 'risk' => 'review', 'status' => 'applied', 'target_url' => 'https://gs.construction/', 'title' => 't', 'hypothesis' => 'h', 'metric' => 'impressions', 'payload' => ['new_description' => 'New.', 'prev_description' => 'Old description.']]);

        try {
            (new GbpDescriptionApplier)->revert($action);
            $this->fail('A refused revert must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Google did not take the previous description back: {"message":"Update description failed"', $e->getMessage());
        }
    }

    public function test_the_category_sync_patches_the_same_categories_payload(): void
    {
        $this->fakeGoogle([self::INFO.'/locations/222?updateMask=categories' => Http::response(['name' => 'locations/222'])]);

        $result = app(GoogleBusinessProfileService::class)->updateCategories('gcid:remodeler', ['gcid:kitchen_remodeler', 'gcid:bathroom_remodeler']);

        $this->assertSame(['name' => 'locations/222'], $result);
        $this->assertSame([
            self::REFRESH,
            ['PATCH', self::INFO.'/locations/222?updateMask=categories', ['categories' => [
                'primaryCategory' => ['name' => 'categories/gcid:remodeler'],
                'additionalCategories' => [
                    ['name' => 'categories/gcid:kitchen_remodeler'],
                    ['name' => 'categories/gcid:bathroom_remodeler'],
                ],
            ]]],
        ], $this->sent());
        $this->assertBearerOnGoogleApiCalls();
    }

    public function test_the_service_area_sync_reads_geocodes_and_patches_the_same_way(): void
    {
        $this->fakeGoogle([
            self::INFO.'/locations/222?readMask=serviceArea' => Http::response(['serviceArea' => ['businessType' => 'CUSTOMER_LOCATION_ONLY']]),
            self::PLACES => fn (Request $request) => Http::response(['places' => [['id' => 'ChIJ-'.str_replace([',', ' '], '', $request['textQuery'])]]]),
            self::INFO.'/locations/222?updateMask=serviceArea' => Http::response(['name' => 'locations/222']),
        ]);

        $result = app(GoogleBusinessProfileService::class)->updateServiceArea(['Palatine, IL, USA', 'Cook County, IL, USA']);

        $this->assertSame(['name' => 'locations/222'], $result);
        $this->assertSame([
            self::REFRESH,
            ['GET', self::INFO.'/locations/222?readMask=serviceArea', []],
            ['POST', self::PLACES, ['textQuery' => 'Palatine, IL, USA']],
            ['POST', self::PLACES, ['textQuery' => 'Cook County, IL, USA']],
            ['PATCH', self::INFO.'/locations/222?updateMask=serviceArea', ['serviceArea' => [
                'businessType' => 'CUSTOMER_LOCATION_ONLY',
                'places' => ['placeInfos' => [
                    ['placeName' => 'Palatine, IL, USA', 'placeId' => 'ChIJ-PalatineILUSA'],
                    ['placeName' => 'Cook County, IL, USA', 'placeId' => 'ChIJ-CookCountyILUSA'],
                ]],
            ]]],
        ], $this->sent());
        $this->assertBearerOnGoogleApiCalls();

        Http::assertSent(fn (Request $request) => $request->url() === self::PLACES
            && $request->header('X-Goog-Api-Key') === ['places-key']
            && $request->header('X-Goog-FieldMask') === ['places.id,places.formattedAddress']);
    }

    /** "Keep the current business type" never rewrites a type it could not read. */
    public function test_the_service_area_sync_never_rewrites_a_business_type_it_could_not_read(): void
    {
        $this->fakeGoogle([
            self::INFO.'/locations/222?readMask=serviceArea' => Http::failedConnection('timed out'),
        ]);

        $gbp = app(GoogleBusinessProfileService::class);

        $this->assertNull($gbp->updateServiceArea(['Palatine, IL, USA']));
        $this->assertSame('Google did not answer', $gbp->getLastError()['message']);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH' || $request->url() === self::PLACES);
    }

    public function test_the_service_items_sync_patches_the_same_items_payload(): void
    {
        $this->fakeGoogle([self::INFO.'/locations/222?updateMask=serviceItems' => Http::response(['name' => 'locations/222'])]);

        app(GoogleBusinessProfileService::class)->updateServiceItems([
            ['name' => 'Kitchen remodeling', 'description' => 'Full kitchen remodels.', 'price_cents' => 1234567],
            ['name' => 'Bathroom remodeling'],
        ]);

        $this->assertSame([
            self::REFRESH,
            ['PATCH', self::INFO.'/locations/222?updateMask=serviceItems', ['serviceItems' => [
                [
                    'freeFormServiceItem' => [
                        'category' => 'gcid:remodeler',
                        'label' => ['displayName' => 'Kitchen remodeling', 'languageCode' => 'en', 'description' => 'Full kitchen remodels.'],
                    ],
                    'price' => ['currencyCode' => 'USD', 'units' => '12345', 'nanos' => 670000000],
                ],
                [
                    'freeFormServiceItem' => [
                        'category' => 'gcid:remodeler',
                        'label' => ['displayName' => 'Bathroom remodeling', 'languageCode' => 'en'],
                    ],
                ],
            ]]],
        ], $this->sent());
    }

    /* ------------------------------------------------------------------ */
    /*  Posting                                                            */
    /* ------------------------------------------------------------------ */

    public function test_a_post_is_the_same_local_post_on_the_sites_own_listing(): void
    {
        $this->fakeGoogle([self::V4.'/accounts/111/locations/222/localPosts' => Http::response([
            'name' => 'accounts/111/locations/222/localPosts/p1',
            'searchUrl' => 'https://local.google.com/place?id=1&use=posts&lpsid=p1',
        ])]);

        $summary = str_repeat('Kitchen remodel in Palatine. ', 80); // over Google's 1,500 characters

        $result = app(GoogleBusinessProfileService::class)->createLocalPost(
            'https://gs.construction/storage/projects/kitchen-gbp.jpg',
            $summary,
            'https://gs.construction/projects/kitchen-remodel',
        );

        $this->assertSame('accounts/111/locations/222/localPosts/p1', $result['name']);
        $this->assertSame('https://local.google.com/place?id=1&use=posts&lpsid=p1', $result['searchUrl']);
        $this->assertSame([
            self::REFRESH,
            ['POST', self::V4.'/accounts/111/locations/222/localPosts', [
                'languageCode' => 'en',
                'summary' => mb_substr($summary, 0, 1500),
                'callToAction' => ['actionType' => 'LEARN_MORE', 'url' => 'https://gs.construction/projects/kitchen-remodel'],
                'media' => [['mediaFormat' => 'PHOTO', 'sourceUrl' => 'https://gs.construction/storage/projects/kitchen-gbp.jpg']],
                'topicType' => 'STANDARD',
            ]],
        ], $this->sent());
        $this->assertBearerOnGoogleApiCalls();
    }

    public function test_a_refused_post_keeps_the_message_the_failed_post_row_stores(): void
    {
        $this->fakeGoogle([self::V4.'/accounts/111/locations/222/localPosts' => Http::response(['error' => ['code' => 403, 'message' => 'Denied', 'status' => 'PERMISSION_DENIED']], 403)]);

        $gbp = app(GoogleBusinessProfileService::class);

        $this->assertNull($gbp->createLocalPost('https://gs.construction/storage/p.jpg', 'Summary', 'https://gs.construction/'));
        $this->assertSame('GBP local post failed', $gbp->getLastError()['message']);
        $this->assertSame(403, $gbp->getLastError()['status']);
    }

    public function test_posting_is_refused_before_google_when_no_listing_is_chosen(): void
    {
        config(['services.google.business_profile.location_id' => null]);
        $this->fakeGoogle();

        $gbp = app(GoogleBusinessProfileService::class);

        $this->assertFalse($gbp->isConfigured());
        $this->assertNull($gbp->createLocalPost('https://gs.construction/storage/p.jpg', 'Summary', 'https://gs.construction/'));
        $this->assertSame(['message' => 'GBP not configured'], $gbp->getLastError());
        $this->assertSame([], $this->sent());
    }

    /* ------------------------------------------------------------------ */
    /*  Reviews                                                            */
    /* ------------------------------------------------------------------ */

    public function test_the_review_sync_reads_every_page_of_the_sites_own_listing(): void
    {
        $this->fakeGoogle([
            self::V4.'/accounts/111/locations/222/reviews?pageSize=50' => Http::response(['reviews' => [['name' => 'accounts/111/locations/222/reviews/r1']], 'nextPageToken' => 'p2', 'totalReviewCount' => 2]),
            self::V4.'/accounts/111/locations/222/reviews?pageSize=50&pageToken=p2' => Http::response(['reviews' => [['name' => 'accounts/111/locations/222/reviews/r2']], 'totalReviewCount' => 2]),
        ]);

        $reviews = app(GoogleBusinessProfileService::class)->fetchAllReviews();

        $this->assertSame(['accounts/111/locations/222/reviews/r1', 'accounts/111/locations/222/reviews/r2'], array_column($reviews, 'name'));
        $this->assertSame([
            self::REFRESH,
            ['GET', self::V4.'/accounts/111/locations/222/reviews?pageSize=50', []],
            ['GET', self::V4.'/accounts/111/locations/222/reviews?pageSize=50&pageToken=p2', []],
        ], $this->sent());
        $this->assertBearerOnGoogleApiCalls();
    }

    public function test_a_review_reply_is_the_same_put(): void
    {
        $this->fakeGoogle([self::V4.'/accounts/111/locations/222/reviews/r1/reply' => Http::response(['comment' => 'Thank you!'])]);

        $result = app(GoogleBusinessProfileService::class)->replyToReview('accounts/111/locations/222/reviews/r1', 'Thank you!');

        $this->assertSame(['comment' => 'Thank you!'], $result);
        $this->assertSame([
            self::REFRESH,
            ['PUT', self::V4.'/accounts/111/locations/222/reviews/r1/reply', ['comment' => 'Thank you!']],
        ], $this->sent());
    }

    public function test_the_central_admins_review_import_reads_the_same_page_and_answers_the_same_shape(): void
    {
        $this->fakeGoogle([self::V4.'/accounts/900/locations/333/reviews?pageSize=50' => Http::response([
            'reviews' => [[
                'name' => 'accounts/900/locations/333/reviews/rv1',
                'reviewer' => ['displayName' => 'Ann B.'],
                'starRating' => 'FIVE',
                'comment' => 'Great kitchen.',
                'createTime' => '2026-01-02T03:04:05Z',
            ]],
            'totalReviewCount' => 1,
            'averageRating' => 5,
        ])]);

        $data = $this->getJson('/api/admin/v1/platforms/gbp/reviews?account_id=accounts/900&location_id=locations/333', $this->adminHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame([
            'reviews' => [[
                'id' => 'rv1',
                'reviewer' => 'Ann B.',
                'rating' => 5,
                'comment' => 'Great kitchen.',
                'created_at' => '2026-01-02T03:04:05Z',
                'url' => 'https://www.google.com/maps/reviews?reviewid=rv1',
                'imported' => false,
            ]],
            'next_page_token' => null,
            'total_review_count' => 1,
            'average_rating' => 5,
        ], $data);
        $this->assertSame([
            self::REFRESH,
            ['GET', self::V4.'/accounts/900/locations/333/reviews?pageSize=50', []],
        ], $this->sent());
    }

    /* ------------------------------------------------------------------ */
    /*  Photos                                                             */
    /* ------------------------------------------------------------------ */

    public function test_the_central_admins_photo_upload_sends_the_same_media_and_answers_the_same_shape(): void
    {
        $image = $this->projectImage();
        $this->fakeGoogle([self::V4.'/accounts/900/locations/333/media' => Http::response([
            'name' => 'accounts/900/locations/333/media/m1',
            'googleUrl' => 'https://lh3.googleusercontent.com/p/m1',
        ])]);

        $data = $this->postJson('/api/admin/v1/platforms/gbp/media', [
            'account_id' => '900',
            'location_id' => 'locations/333',
            'image_id' => $image->id,
            'captured_at' => '2021-01-01',
            'latitude' => 42.11,
            'longitude' => -88.03,
        ], $this->adminHeaders())->assertOk()->json('data');

        $this->assertSame([
            'ok' => true,
            'image_id' => $image->id,
            'media_name' => 'accounts/900/locations/333/media/m1',
            'media_url' => 'https://lh3.googleusercontent.com/p/m1=s0',
        ], $data);

        $copy = collect(Storage::disk('public')->files('projects'))->first(fn (string $f) => str_contains($f, '-gbp-'));
        $this->assertNotNull($copy, 'the dated, geotagged Google copy was made');

        $this->assertSame([
            self::REFRESH,
            ['POST', self::V4.'/accounts/900/locations/333/media', [
                'mediaFormat' => 'PHOTO',
                'locationAssociation' => ['category' => 'ADDITIONAL'],
                'sourceUrl' => 'https://gs.construction/storage/'.$copy,
                'description' => 'A finished kitchen remodel.',
            ]],
        ], $this->sent());
        $this->assertBearerOnGoogleApiCalls();
        $upload = ImagePlatformUpload::where('project_image_id', $image->id)->where('platform', ImagePlatformUpload::PLATFORM_GOOGLE_PLACES)->firstOrFail();
        $this->assertSame('accounts/900/locations/333/media/m1', $upload->remote_id);
        $this->assertSame('https://lh3.googleusercontent.com/p/m1=s0', $upload->remote_url);
        $this->assertSame(['account_id' => '900', 'location_id' => '333'], $upload->metadata);
    }

    public function test_the_central_admins_photo_delete_and_media_list_are_the_same_calls(): void
    {
        $this->fakeGoogle([
            self::V4.'/accounts/900/locations/333/media/m1' => Http::response([], 200),
            self::V4.'/accounts/900/locations/333/media?pageSize=100' => Http::response(['mediaItems' => [[
                'name' => 'accounts/900/locations/333/media/m2',
                'sourceUrl' => 'https://gs.construction/storage/projects/k-gbp-abc.jpg',
                'googleUrl' => 'https://lh3.googleusercontent.com/p/m2',
                'locationAssociation' => ['category' => 'ADDITIONAL'],
                'createTime' => '2026-09-22T10:00:00Z',
            ]]]),
        ]);

        $this->deleteJson('/api/admin/v1/platforms/gbp/media', ['media_name' => 'accounts/900/locations/333/media/m1'], $this->adminHeaders())
            ->assertNoContent();

        $data = $this->getJson('/api/admin/v1/platforms/gbp/media?account_id=900&location_id=333', $this->adminHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(['items' => [[
            'name' => 'accounts/900/locations/333/media/m2',
            'source_url' => 'https://gs.construction/storage/projects/k-gbp-abc.jpg',
            'google_url' => 'https://lh3.googleusercontent.com/p/m2',
            'category' => 'ADDITIONAL',
            'create_time' => '2026-09-22T10:00:00Z',
        ]], 'count' => 1], $data);
        $this->assertSame([
            self::REFRESH,
            ['DELETE', self::V4.'/accounts/900/locations/333/media/m1', []],
            ['GET', self::V4.'/accounts/900/locations/333/media?pageSize=100', []],
        ], $this->sent());
    }

    public function test_the_sites_own_photo_upload_and_media_health_check_are_the_same_calls(): void
    {
        $image = $this->projectImage();
        $this->fakeGoogle([
            self::V4.'/accounts/111/locations/222/media' => Http::response(['name' => 'accounts/111/locations/222/media/own1', 'googleUrl' => 'https://lh3.googleusercontent.com/p/own1']),
            self::V4.'/accounts/111/locations/222/media?pageSize=1' => Http::response(['mediaItems' => [], 'totalMediaItemCount' => 0]),
        ]);

        $gbp = app(GoogleBusinessProfileService::class);
        $result = $gbp->uploadProjectImage($image);
        $page = $gbp->listMedia(null, 1);

        $this->assertSame('accounts/111/locations/222/media/own1', $result['name']);
        $this->assertSame('https://lh3.googleusercontent.com/p/own1=s0', $result['url']);
        $this->assertSame(['mediaItems' => [], 'totalMediaItemCount' => 0], $page);

        $sent = $this->sent();
        $this->assertSame(self::REFRESH, $sent[0]);
        $this->assertSame('POST', $sent[1][0]);
        $this->assertSame(self::V4.'/accounts/111/locations/222/media', $sent[1][1]);
        $this->assertSame(['mediaFormat', 'locationAssociation', 'sourceUrl', 'description'], array_keys($sent[1][2]));
        $this->assertSame(['category' => 'ADDITIONAL'], $sent[1][2]['locationAssociation']);
        $this->assertStringStartsWith('https://gs.construction/storage/projects/', $sent[1][2]['sourceUrl']);
        $this->assertStringContainsString('-gbp-', $sent[1][2]['sourceUrl']);
        $this->assertSame('A finished kitchen remodel.', $sent[1][2]['description']);
        $this->assertSame(['GET', self::V4.'/accounts/111/locations/222/media?pageSize=1', []], $sent[2]);
        $this->assertCount(3, $sent);
    }
}
