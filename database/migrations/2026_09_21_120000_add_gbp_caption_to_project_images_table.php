<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('project_images', function (Blueprint $table) {
            // The Google Business Profile photo caption (GooglePhotoCaptionPrompt,
            // <=250 chars): generated alongside alt_text/caption/seo_alt_text but
            // kept separate since it is written for Google's photo gallery, not
            // for this site's own pages. buildDescription() prefers it over the
            // site's own caption/alt text when present.
            $table->text('gbp_caption')->nullable()->after('caption');
        });
    }

    public function down(): void
    {
        Schema::table('project_images', function (Blueprint $table) {
            $table->dropColumn('gbp_caption');
        });
    }
};
