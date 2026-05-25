<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hive_project_zip_counts', function (Blueprint $table) {
            $table->decimal('zip_latitude', 10, 7)->nullable()->after('longitude');
            $table->decimal('zip_longitude', 10, 7)->nullable()->after('zip_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('hive_project_zip_counts', function (Blueprint $table) {
            $table->dropColumn(['zip_latitude', 'zip_longitude']);
        });
    }
};
