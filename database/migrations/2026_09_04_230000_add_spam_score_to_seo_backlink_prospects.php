<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_backlink_prospects', function (Blueprint $table) {
            $table->unsignedTinyInteger('spam_score')->nullable()->after('platform_type');
        });
    }

    public function down(): void
    {
        Schema::table('seo_backlink_prospects', fn (Blueprint $table) => $table->dropColumn('spam_score'));
    }
};
