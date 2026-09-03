<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a partner likely provided on the project, estimated from their site when no note was entered. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_collaborators', function (Blueprint $table) {
            $table->text('inferred_note')->nullable()->after('note');
            $table->timestamp('inferred_at')->nullable()->after('site_fetched_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_collaborators', fn (Blueprint $table) => $table->dropColumn(['inferred_note', 'inferred_at']));
    }
};
