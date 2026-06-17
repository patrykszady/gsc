<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_errors', function (Blueprint $table) {
            $table->id();
            // Stable hash of kind+message+source+line so repeat occurrences of
            // the same error collapse into one row (with an occurrence count).
            $table->string('fingerprint', 64)->unique();
            $table->string('kind', 32)->default('error'); // error | promise
            $table->string('message', 500);
            $table->string('source', 255)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->unsignedInteger('column')->nullable();
            $table->text('stack')->nullable();          // latest sample
            $table->string('page_path', 255)->nullable(); // latest sample
            $table->string('user_agent', 300)->nullable(); // latest sample
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('last_seen_at');
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_errors');
    }
};
