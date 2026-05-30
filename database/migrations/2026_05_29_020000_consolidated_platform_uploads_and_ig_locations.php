<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated migration replacing 10 pending migrations:
 *
 *  - 2026_05_27_120000_add_google_places_media_url_to_project_images_table
 *  - 2026_05_27_130000_drop_yelp_biz_photos_url_from_project_images
 *  - 2026_05_27_140000_create_gsc_coverage_states_table
 *  - 2026_05_28_200000_create_image_platform_uploads_table
 *  - 2026_05_28_201000_add_ig_location_id_to_projects
 *  - 2026_05_28_202000_move_ig_location_id_to_areas_served
 *  - 2026_05_28_210000_drop_ig_location_resolved_at_from_areas_served
 *  - 2026_05_28_211000_drop_legacy_platform_columns_from_project_images
 *  - 2026_05_28_211500_drop_disk_from_project_images
 *  - 2026_05_28_212000_rename_social_media_posts_to_image_social_posts
 *  - 2026_05_29_011911_add_metadata_to_oauth_tokens_table
 *
 * Also seeds areas_served.ig_location_id from a known mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. image_platform_uploads (replaces per-platform columns on project_images)
        Schema::create('image_platform_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_image_id')->constrained()->cascadeOnDelete();
            $table->string('platform'); // google_places | yelp_portfolio | yelp_biz
            $table->string('remote_id')->nullable();
            $table->text('remote_url')->nullable();
            $table->text('caption')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['project_image_id', 'platform']);
            $table->index('platform');
            $table->index('uploaded_at');
        });

        // 2. Backfill image_platform_uploads from legacy project_images columns
        if (Schema::hasColumn('project_images', 'google_places_media_name')) {
            DB::table('project_images')
                ->whereNotNull('google_places_uploaded_at')
                ->orWhereNotNull('google_places_media_name')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    $now = now();
                    $insert = [];
                    foreach ($rows as $r) {
                        if (! $r->google_places_media_name && ! $r->google_places_uploaded_at) {
                            continue;
                        }
                        $insert[] = [
                            'project_image_id' => $r->id,
                            'platform' => 'google_places',
                            'remote_id' => $r->google_places_media_name,
                            'remote_url' => null,
                            'caption' => null,
                            'uploaded_at' => $r->google_places_uploaded_at,
                            'metadata' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($insert) {
                        DB::table('image_platform_uploads')->insertOrIgnore($insert);
                    }
                });
        }

        if (Schema::hasColumn('project_images', 'yelp_photo_id')) {
            DB::table('project_images')
                ->whereNotNull('yelp_uploaded_at')
                ->orWhereNotNull('yelp_photo_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    $now = now();
                    $insert = [];
                    foreach ($rows as $r) {
                        if (! $r->yelp_photo_id && ! $r->yelp_uploaded_at) {
                            continue;
                        }
                        $insert[] = [
                            'project_image_id' => $r->id,
                            'platform' => 'yelp_portfolio',
                            'remote_id' => $r->yelp_photo_id,
                            'remote_url' => null,
                            'caption' => null,
                            'uploaded_at' => $r->yelp_uploaded_at,
                            'metadata' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($insert) {
                        DB::table('image_platform_uploads')->insertOrIgnore($insert);
                    }
                });
        }

        if (Schema::hasColumn('project_images', 'yelp_biz_photo_id')) {
            DB::table('project_images')
                ->whereNotNull('yelp_biz_uploaded_at')
                ->orWhereNotNull('yelp_biz_photo_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    $now = now();
                    $insert = [];
                    foreach ($rows as $r) {
                        if (! $r->yelp_biz_photo_id && ! $r->yelp_biz_uploaded_at) {
                            continue;
                        }
                        $insert[] = [
                            'project_image_id' => $r->id,
                            'platform' => 'yelp_biz',
                            'remote_id' => $r->yelp_biz_photo_id,
                            'remote_url' => null,
                            'caption' => $r->yelp_biz_caption ?? null,
                            'uploaded_at' => $r->yelp_biz_uploaded_at,
                            'metadata' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($insert) {
                        DB::table('image_platform_uploads')->insertOrIgnore($insert);
                    }
                });
        }

        // 3. Drop legacy per-platform + disk columns from project_images
        Schema::table('project_images', function (Blueprint $table) {
            $drops = [];
            foreach ([
                'google_places_media_name',
                'google_places_uploaded_at',
                'yelp_photo_id',
                'yelp_uploaded_at',
                'yelp_biz_photo_id',
                'yelp_biz_uploaded_at',
                'yelp_biz_photos_url',
                'yelp_biz_caption',
                'disk',
            ] as $col) {
                if (Schema::hasColumn('project_images', $col)) {
                    $drops[] = $col;
                }
            }
            if ($drops) {
                $table->dropColumn($drops);
            }
        });

        // 4. GSC coverage state tracking
        Schema::create('gsc_coverage_states', function (Blueprint $table) {
            $table->id();
            $table->string('url', 500);
            $table->string('verdict', 32)->nullable();
            $table->string('coverage_state', 191)->nullable();
            $table->string('robots_txt_state', 32)->nullable();
            $table->string('indexing_state', 32)->nullable();
            $table->string('page_fetch_state', 32)->nullable();
            $table->string('sitemap_url', 500)->nullable();
            $table->timestamp('last_crawl_time')->nullable();
            $table->string('user_canonical', 500)->nullable();
            $table->string('google_canonical', 500)->nullable();
            $table->timestamp('inspected_at');
            $table->timestamp('last_changed_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->unique('url', 'gsc_coverage_url_unique');
            $table->index(['verdict', 'coverage_state'], 'gsc_coverage_state_idx');
            $table->index('inspected_at', 'gsc_coverage_inspected_idx');
        });

        Schema::create('gsc_coverage_state_history', function (Blueprint $table) {
            $table->id();
            $table->string('url', 500);
            $table->string('verdict', 32)->nullable();
            $table->string('coverage_state', 191)->nullable();
            $table->string('page_fetch_state', 32)->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['url', 'observed_at'], 'gsc_coverage_hist_url_idx');
        });

        // 5. areas_served.ig_location_id + fb_place_id (per-city platform IDs)
        Schema::table('areas_served', function (Blueprint $table) {
            if (! Schema::hasColumn('areas_served', 'ig_location_id')) {
                $table->string('ig_location_id')->nullable()->after('longitude');
                $table->index('ig_location_id');
            }
            if (! Schema::hasColumn('areas_served', 'fb_place_id')) {
                $table->string('fb_place_id')->nullable()->after('ig_location_id');
                $table->index('fb_place_id');
            }
        });

        // 6. Seed ig_location_id + fb_place_id from CSV (idempotent: only fills NULL cells)
        $csv = database_path('data/areas_served_ids.csv');
        if (is_file($csv)) {
            $fh = fopen($csv, 'r');
            $header = fgetcsv($fh);
            if ($header !== false) {
                $idx = array_flip($header);
                while (($row = fgetcsv($fh)) !== false) {
                    $id = (int) ($row[$idx['id']] ?? 0);
                    if ($id <= 0) continue;
                    $ig = trim((string) ($row[$idx['ig_location_id']] ?? ''));
                    $fb = trim((string) ($row[$idx['fb_place_id']] ?? ''));
                    if ($ig === '' && $fb === '') continue;

                    $existing = DB::table('areas_served')->where('id', $id)
                        ->first(['ig_location_id', 'fb_place_id']);
                    if (! $existing) continue;

                    $patch = [];
                    if ($ig !== '' && empty($existing->ig_location_id)) $patch['ig_location_id'] = $ig;
                    if ($fb !== '' && empty($existing->fb_place_id)) $patch['fb_place_id'] = $fb;
                    if ($patch) {
                        DB::table('areas_served')->where('id', $id)->update($patch);
                    }
                }
            }
            fclose($fh);
        }

        // 7. Rename social_media_posts → image_social_posts
        if (Schema::hasTable('social_media_posts') && ! Schema::hasTable('image_social_posts')) {
            Schema::rename('social_media_posts', 'image_social_posts');
        }

        // 8. oauth_tokens.metadata
        Schema::table('oauth_tokens', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('granted_by_email');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_tokens', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });

        if (Schema::hasTable('image_social_posts') && ! Schema::hasTable('social_media_posts')) {
            Schema::rename('image_social_posts', 'social_media_posts');
        }

        Schema::table('areas_served', function (Blueprint $table) {
            if (Schema::hasColumn('areas_served', 'fb_place_id')) {
                $table->dropIndex(['fb_place_id']);
                $table->dropColumn('fb_place_id');
            }
            $table->dropIndex(['ig_location_id']);
            $table->dropColumn('ig_location_id');
        });

        Schema::dropIfExists('gsc_coverage_state_history');
        Schema::dropIfExists('gsc_coverage_states');

        Schema::table('project_images', function (Blueprint $table) {
            $table->string('google_places_media_name')->nullable();
            $table->timestamp('google_places_uploaded_at')->nullable();
            $table->string('yelp_photo_id')->nullable();
            $table->timestamp('yelp_uploaded_at')->nullable();
            $table->string('yelp_biz_photo_id')->nullable();
            $table->timestamp('yelp_biz_uploaded_at')->nullable();
            $table->string('yelp_biz_photos_url')->nullable();
            $table->text('yelp_biz_caption')->nullable();
            $table->string('disk')->default('public');
        });

        Schema::dropIfExists('image_platform_uploads');
    }
};
