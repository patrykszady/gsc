<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->string('google_review_id')->nullable()->unique()->after('review_image');
            $table->unsignedTinyInteger('star_rating')->nullable()->after('google_review_id');
        });
    }

    public function down(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->dropColumn(['google_review_id', 'star_rating']);
        });
    }
};
