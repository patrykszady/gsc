<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // PageSpeed Insights snapshots. Captures both Lighthouse (lab) scores
        // and CrUX (real-user) Core Web Vitals when available.
        Schema::create('psi_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('url', 500);
            $table->enum('strategy', ['mobile', 'desktop']);
            // Lighthouse category scores (0-100)
            $table->unsignedTinyInteger('performance')->nullable();
            $table->unsignedTinyInteger('accessibility')->nullable();
            $table->unsignedTinyInteger('best_practices')->nullable();
            $table->unsignedTinyInteger('seo')->nullable();
            // Lab metrics (ms)
            $table->unsignedInteger('lab_lcp_ms')->nullable();
            $table->unsignedInteger('lab_fcp_ms')->nullable();
            $table->unsignedInteger('lab_tbt_ms')->nullable();
            $table->decimal('lab_cls', 6, 3)->nullable();
            $table->unsignedInteger('lab_si_ms')->nullable();
            // Field (CrUX) Core Web Vitals
            $table->unsignedInteger('field_lcp_ms')->nullable();
            $table->unsignedInteger('field_inp_ms')->nullable();
            $table->decimal('field_cls', 6, 3)->nullable();
            $table->string('field_overall', 16)->nullable(); // FAST / AVERAGE / SLOW
            $table->timestamps();

            $table->index(['url', 'strategy', 'date']);
            $table->unique(['date', 'url', 'strategy'], 'psi_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psi_snapshots');
    }
};
