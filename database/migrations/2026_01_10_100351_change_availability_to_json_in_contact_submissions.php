<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, clear or convert any existing non-JSON values to NULL
        DB::table('contact_submissions')
            ->whereNotNull('availability')
            ->update(['availability' => null]);

        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->json('availability')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->string('availability')->nullable()->change();
        });
    }
};
