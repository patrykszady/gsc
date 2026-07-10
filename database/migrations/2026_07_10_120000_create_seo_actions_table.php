<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SEO Autopilot ledger — the "brain" of the self-improving loop.
 *
 * One row per recommended action. The autopilot synthesizes these from the
 * existing seo:* analysis signals, (optionally) auto-applies the safe/reversible
 * ones, captures a baseline of the target metric at apply-time, then revisits
 * after a measurement window to record whether the change actually moved the
 * metric. Those outcomes feed back into the scoring weights so the system
 * favours action types that have historically worked on THIS site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_actions', function (Blueprint $table) {
            $table->id();

            // Stable dedupe key so the same recommendation isn't re-proposed
            // every run while it's still open (source + category + target).
            $table->string('fingerprint')->unique();

            // Where the recommendation came from and what kind of change it is.
            $table->string('source');                 // striking_distance, zero_click, coverage_error, content_decay, orphan, llms_stale, ...
            $table->string('category')->index();      // title_meta, reindex, llms_regen, internal_link, schema, gbp, content
            $table->string('risk')->default('review'); // safe | review | manual (auto-apply eligibility gate)

            // The target: either a HasSEO model (polymorphic) or a bare URL.
            $table->nullableMorphs('target');
            $table->string('target_url')->nullable();

            // Human-facing explanation + the machine payload of the change
            // (includes the previous value so apply is always reversible).
            $table->string('title');
            $table->text('hypothesis')->nullable();
            $table->json('payload')->nullable();

            // Prioritization (kept explicit for transparency in the admin UI).
            $table->float('priority')->default(0)->index();
            $table->float('impact_score')->default(0);
            $table->float('confidence')->default(0);
            $table->float('ease')->default(0);

            // Lifecycle.
            $table->string('status')->default('proposed')->index(); // proposed | applied | skipped | reverted | failed
            $table->boolean('auto_applied')->default(false);
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->text('error')->nullable();

            // The learning loop: baseline captured at apply, re-measured later.
            $table->string('metric')->nullable();          // clicks | ctr | position | impressions
            $table->float('baseline_value')->nullable();
            $table->timestamp('baseline_at')->nullable();
            $table->timestamp('measure_after')->nullable()->index();
            $table->float('measured_value')->nullable();
            $table->timestamp('measured_at')->nullable();
            $table->float('delta_pct')->nullable();
            $table->string('outcome')->nullable()->index(); // pending | worked | no_effect | regressed | inconclusive

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_actions');
    }
};
