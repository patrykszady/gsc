<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Learned allow/deny rules for the contact-form spam filter.
     *
     * When an admin converts a spam submission to a real lead we add `allow`
     * rules so the same sender is trusted next time; when an admin flags a real
     * lead as spam we add `deny` rules so similar senders are blocked. This lets
     * the heuristic filter self-heal from operator corrections.
     */
    public function up(): void
    {
        Schema::create('lead_filter_rules', function (Blueprint $table) {
            $table->id();
            $table->string('action', 8);       // allow | deny
            $table->string('match_type', 12);  // email | phone | domain | ip
            $table->string('value');           // normalized (lowercase email/domain, digits-only phone)
            $table->string('note')->nullable();
            $table->foreignId('submission_id')->nullable();
            $table->timestamps();

            $table->unique(['action', 'match_type', 'value']);
            $table->index(['match_type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_filter_rules');
    }
};
