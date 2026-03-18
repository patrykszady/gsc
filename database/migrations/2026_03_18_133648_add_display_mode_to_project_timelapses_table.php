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
        Schema::table('project_timelapses', function (Blueprint $table) {
            $table->string('display_mode', 20)->default('slider')->after('title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_timelapses', function (Blueprint $table) {
            $table->dropColumn('display_mode');
        });
    }
};
