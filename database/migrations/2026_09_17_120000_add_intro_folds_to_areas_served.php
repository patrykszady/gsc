<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The town's long copy sorted into the lead and the two accordion folds the
 * town page shows (seo:split-area-intros): {lead, history, potential,
 * source_hash, model, generated_at}. source_hash fingerprints the local_intro
 * the split was made from; an edit in admin retires it until the next run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas_served', function (Blueprint $table) {
            $table->json('intro_folds')->nullable()->after('permit_notes');
        });
    }

    public function down(): void
    {
        Schema::table('areas_served', function (Blueprint $table) {
            $table->dropColumn('intro_folds');
        });
    }
};
