<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Houzz import no longer has an on/off switch — any active site with a
 * Houzz profile URL imports weekly, like Yelp. Drop the rows the previous
 * migration and the short-lived Platforms checkbox wrote.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->where('key', 'houzz.reviews.enabled')->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the switch is gone.
    }
};
