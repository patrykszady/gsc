<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hive_project_zip_counts', function (Blueprint $table) {
            $table->id();
            $table->string('zip', 10);
            $table->string('city', 100)->nullable();
            $table->string('state', 10)->nullable();
            $table->unsignedInteger('count');
            $table->timestamp('synced_at')->useCurrent();
            $table->unique(['zip', 'city', 'state'], 'hpzc_zip_city_state_unique');
            $table->index('zip');
            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hive_project_zip_counts');
    }
};
