<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Append `=s0` to bare googleusercontent.com media URLs.
 *
 * A bare googleusercontent URL serves a 512px / ~53KB rendition, not the
 * original. Older rows were stored with `=s0` already; uploads from
 * 2026-06-14 onward recorded the raw URL Google returns, so those photos
 * showed a low-quality image behind "View on Google", in the ImageObject
 * sameAs, and in the sitemap's <image:loc>.
 *
 * The uploads themselves were always full-resolution — only the size token
 * was missing. GoogleBusinessProfileService now sizes URLs at the point it
 * returns them, so new rows are correct on write; this fixes the ones
 * already stored.
 *
 * Idempotent: rows that already carry a size token are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('image_platform_uploads')
            ->whereNotNull('remote_url')
            ->where('remote_url', 'like', '%googleusercontent.com%')
            ->select('id', 'remote_url')
            ->get();

        foreach ($rows as $row) {
            // Same rule as GoogleBusinessProfileService::sizedMediaUrl().
            if (preg_match('/=[a-z0-9-]+$/i', $row->remote_url)) {
                continue;
            }

            DB::table('image_platform_uploads')
                ->where('id', $row->id)
                ->update(['remote_url' => $row->remote_url . '=s0']);
        }
    }

    public function down(): void
    {
        // Deliberately not reversed. Stripping `=s0` would also strip it from
        // the rows that were stored sized long before this migration, leaving
        // the data worse than it started.
    }
};
