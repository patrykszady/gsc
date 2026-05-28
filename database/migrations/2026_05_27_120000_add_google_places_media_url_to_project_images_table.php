<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('project_images', function (Blueprint $table) {
            // Public lh3.googleusercontent.com URL returned by the GBP
            // media.create endpoint. Captured once at upload time so the
            // "View on Google" link on /projects/.../photos/... renders
            // without hitting the GBP API on every request.
            $table->text('google_places_media_url')->nullable()->after('google_places_media_name');
        });
    }

    public function down(): void
    {
        Schema::table('project_images', function (Blueprint $table) {
            $table->dropColumn('google_places_media_url');
        });
    }
};
