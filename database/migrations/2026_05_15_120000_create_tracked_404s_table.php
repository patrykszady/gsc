<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracked_404s', function (Blueprint $table) {
            $table->id();
            $table->string('path', 500)->unique();
            $table->string('referer', 500)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedInteger('hit_count')->default(1);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('indexnow_submitted_at')->nullable();
            $table->timestamps();

            $table->index('last_seen_at');
            $table->index('indexnow_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_404s');
    }
};
