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
        Schema::table('project_images', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('caption');
            $table->string('seo_alt_text')->nullable()->after('slug');
            
            // Composite unique: slug must be unique within each project
            $table->unique(['project_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_images', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'slug']);
            $table->dropColumn(['slug', 'seo_alt_text']);
        });
    }
};
