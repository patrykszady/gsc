<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DataForSEO intelligence, every API family on a schedule: each run stores
 * what it measured (snapshots), the runner compares it with the previous run
 * and keeps the differences that matter as findings (opened on first sight,
 * resolved when a later run no longer reports them). Runs carry the cost so
 * the spend per family is visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_intel_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('family', 40);
            $table->string('run_id', 40);
            $table->date('taken_on');
            $table->decimal('cost', 8, 4)->default(0);
            $table->unsignedInteger('snapshots')->default(0);
            $table->unsignedInteger('findings_open')->default(0);
            $table->unsignedInteger('findings_new')->default(0);
            $table->unsignedInteger('findings_resolved')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'family', 'run_id']);
            $table->index(['site_id', 'family', 'taken_on']);
        });

        Schema::create('seo_intel_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('family', 40);
            $table->string('kind', 40);
            $table->string('subject', 191);
            $table->date('taken_on');
            $table->string('run_id', 40);
            $table->json('metrics')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'family', 'kind', 'subject', 'taken_on'], 'seo_intel_snapshots_unique');
        });

        Schema::create('seo_intel_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('family', 40);
            $table->string('code', 60);
            $table->string('severity', 10);
            $table->string('fingerprint', 40);
            $table->string('subject', 191)->nullable();
            $table->text('key')->nullable();
            $table->string('title', 255);
            $table->text('detail')->nullable();
            $table->json('delta')->nullable();
            $table->json('action')->nullable();
            $table->date('first_seen_on');
            $table->date('last_seen_on');
            $table->string('last_run_id', 40)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('seo_action_id')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'fingerprint']);
            $table->index(['site_id', 'family', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_intel_findings');
        Schema::dropIfExists('seo_intel_snapshots');
        Schema::dropIfExists('seo_intel_runs');
    }
};
